<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\DocumentStatus;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Product;
use App\Models\Proforma;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Recalculates what the ledgers imply and lists every place the data disagrees with it.
 * Read-only. Each check returns human-readable problems; an empty list means it passed.
 */
class LedgerVerifier
{
    /**
     * @return array<string, list<string>> problems by check name
     */
    public function run(): array
    {
        return [
            'Document totals equal the sum of their items' => $this->documentTotals(),
            'Documents have the stock movements their items imply' => $this->documentMovements(),
            'No product has negative stock' => $this->negativeStock(),
            'Payments stay within each document\'s total' => $this->documentPayments(),
            'Each transfer sums to zero' => $this->transferGroups(),
            'Each reversal cancels exactly its entry' => $this->reversals(),
            'Inactive accounts hold no money' => $this->inactiveAccounts(),
        ];
    }

    /**
     * @return list<string>
     */
    private function documentTotals(): array
    {
        $problems = [];
        $documents = [
            [Purchase::class, 'unit_cost'],
            [Sale::class, 'unit_price'],
            [Proforma::class, 'unit_price'],
        ];

        foreach ($documents as [$class, $priceColumn]) {
            $class::query()->with('items:id,'.$this->foreignKey($class).',quantity,'.$priceColumn)
                ->select(['id', 'number', 'total_amount'])
                ->chunkById(500, function ($chunk) use (&$problems, $priceColumn) {
                    foreach ($chunk as $document) {
                        $itemsCents = $document->items->sum(fn ($item) => $item->quantity * Money::toCents($item->{$priceColumn}));

                        if ($itemsCents !== Money::toCents($document->total_amount)) {
                            $problems[] = "{$document->number}: total {$document->total_amount}, items add up to ".Money::fromCents($itemsCents);
                        }
                    }
                });
        }

        return $problems;
    }

    /**
     * Active documents net to their item quantities per product (in for purchases, out for
     * sales); voided documents net to zero because the void reversed them.
     *
     * @return list<string>
     */
    private function documentMovements(): array
    {
        $problems = [];

        foreach ([Purchase::class => 1, Sale::class => -1] as $class => $sign) {
            $type = (new $class)->getMorphClass();
            $moved = StockMovement::query()
                ->where('reference_type', $type)
                ->selectRaw('reference_id, product_id, sum(quantity) as net')
                ->groupBy('reference_id', 'product_id')
                ->get()
                ->mapWithKeys(fn ($row) => ["{$row->reference_id}:{$row->product_id}" => (int) $row->net]);

            $expected = [];
            $class::query()->with('items:id,'.$this->foreignKey($class).',product_id,quantity')
                ->select(['id', 'number', 'status'])
                ->chunkById(500, function ($chunk) use (&$expected, $sign) {
                    foreach ($chunk as $document) {
                        foreach ($document->items as $item) {
                            $expected["{$document->id}:{$item->product_id}"] = [
                                $document->number,
                                $document->status === DocumentStatus::Active ? $sign * $item->quantity : 0,
                            ];
                        }
                    }
                });

            foreach ($expected as $key => [$number, $quantity]) {
                $net = $moved[$key] ?? 0;

                if ($net !== $quantity) {
                    $productId = explode(':', $key)[1];
                    $problems[] = "{$number}, product {$productId}: stock moved {$net}, expected {$quantity}";
                }
            }

            foreach ($moved->except(array_keys($expected)) as $key => $net) {
                [$documentId, $productId] = explode(':', $key);
                $problems[] = "{$type} {$documentId}: stock movements for product {$productId} that is not on the document ({$net})";
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function negativeStock(): array
    {
        return Product::withTrashed()
            ->withStock()
            ->get(['id', 'sku'])
            ->filter(fn (Product $product) => (int) $product->stock < 0)
            ->map(fn (Product $product) => "{$product->sku}: stock is {$product->stock}")
            ->values()
            ->all();
    }

    /**
     * Net payments (payments minus their reversals) are between zero and the total on active
     * documents, and zero on voided ones.
     *
     * @return list<string>
     */
    private function documentPayments(): array
    {
        $problems = [];

        foreach ([Sale::class => 1, Purchase::class => -1] as $class => $sign) {
            $paid = AccountTransaction::query()
                ->where('reference_type', (new $class)->getMorphClass())
                ->selectRaw('reference_id, sum(amount) as net')
                ->groupBy('reference_id')
                ->pluck('net', 'reference_id');

            $class::query()->whereKey($paid->keys())->get(['id', 'number', 'status', 'total_amount'])
                ->each(function (Model $document) use ($paid, $sign, &$problems) {
                    $paidCents = $sign * Money::toCents($paid[$document->id]);
                    $paidText = Money::fromCents($paidCents);

                    if ($document->status !== DocumentStatus::Active && $paidCents !== 0) {
                        $problems[] = "{$document->number} is voided but still has {$paidText} in payments";
                    } elseif ($paidCents < 0 || $paidCents > Money::toCents($document->total_amount)) {
                        $problems[] = "{$document->number}: payments {$paidText}, total {$document->total_amount}";
                    }
                });
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function transferGroups(): array
    {
        return AccountTransaction::query()
            ->whereNotNull('transfer_group')
            ->selectRaw('transfer_group, sum(amount) as net, count(*) as rows_count')
            ->groupBy('transfer_group')
            ->get()
            ->filter(fn ($group) => Money::toCents($group->net) !== 0 || (int) $group->rows_count !== 2)
            ->map(fn ($group) => "Transfer {$group->transfer_group}: {$group->rows_count} rows summing to ".Money::fromCents(Money::toCents($group->net)))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function reversals(): array
    {
        return AccountTransaction::query()
            ->where('type', AccountTransactionType::Reversal)
            ->with('reverses:id,account_id,amount')
            ->get(['id', 'account_id', 'amount', 'reverses_transaction_id'])
            ->filter(fn (AccountTransaction $reversal) => $reversal->reverses === null
                || $reversal->reverses->account_id !== $reversal->account_id
                || Money::toCents($reversal->reverses->amount) !== -Money::toCents($reversal->amount))
            ->map(fn (AccountTransaction $reversal) => "Reversal {$reversal->id} does not cancel transaction {$reversal->reverses_transaction_id}")
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function inactiveAccounts(): array
    {
        return Account::query()
            ->where('is_active', false)
            ->withBalance()
            ->get()
            ->filter(fn (Account $account) => $account->balanceCents() !== 0)
            ->map(fn (Account $account) => "{$account->name} is inactive with a balance of ".Money::fromCents($account->balanceCents()))
            ->values()
            ->all();
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function foreignKey(string $class): string
    {
        return (new $class)->items()->getForeignKeyName();
    }
}
