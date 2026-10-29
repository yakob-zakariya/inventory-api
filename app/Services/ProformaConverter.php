<?php

namespace App\Services;

use App\Enums\ProformaStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Models\Product;
use App\Models\Proforma;
use App\Models\ProformaItem;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns an open, unexpired proforma into a sale at the quoted prices. The sale goes through
 * SaleRecorder, so stock, the walk-in rule and payments are checked exactly as for any sale.
 */
class ProformaConverter
{
    public function __construct(private SaleRecorder $sales) {}

    /**
     * @param  array{customer_id?: ?int, sale_date?: ?string, note?: ?string,
     *     items?: list<array{product_id: int, quantity: int}>,
     *     payments?: list<array{account_id: int, amount: string|int|float, receipt_reference?: ?string}>}  $data
     *
     * @throws ConflictException when the proforma is already converted or cancelled
     * @throws BusinessRuleException when it expired, the items go beyond the quote, or the sale breaks a sale rule
     */
    public function convert(Proforma $proforma, array $data): Sale
    {
        return DB::transaction(function () use ($proforma, $data) {
            // Locking reads only, before the products are locked: a plain read here would fix
            // MySQL's snapshot and hide stock changes that commit while we wait for the product locks.
            $proforma = Proforma::query()->lockForUpdate()->findOrFail($proforma->id);
            $this->assertConvertible($proforma);
            $quoted = $proforma->items()->lockForUpdate()->get()->keyBy('product_id');

            $sale = $this->sales->record([
                'customer_id' => $data['customer_id'] ?? $proforma->customer_id,
                'sale_date' => $data['sale_date'] ?? null,
                'note' => $data['note'] ?? $this->defaultNote($proforma),
                'items' => $this->saleItems($quoted, $data['items'] ?? null),
                'payments' => $data['payments'] ?? [],
            ]);

            $proforma->forceFill([
                'status' => ProformaStatus::Converted,
                'sale_id' => $sale->id,
                'converted_at' => now(),
            ])->save();

            return $sale;
        });
    }

    /**
     * @throws ConflictException|BusinessRuleException
     */
    private function assertConvertible(Proforma $proforma): void
    {
        if ($proforma->status === ProformaStatus::Converted) {
            throw new ConflictException("{$proforma->number} was already converted to a sale.");
        }

        if ($proforma->status === ProformaStatus::Cancelled) {
            throw new ConflictException("{$proforma->number} is cancelled.");
        }

        if ($proforma->isExpired()) {
            throw new BusinessRuleException("{$proforma->number} expired on {$proforma->valid_until->toDateString()}. Create a new proforma with current prices.");
        }
    }

    /**
     * The quoted items at their quoted prices, or the requested subset with lower or equal quantities.
     *
     * @param  Collection<int, ProformaItem>  $quoted  keyed by product id
     * @param  ?list<array{product_id: int, quantity: int}>  $requested
     * @return list<array{product_id: int, quantity: int, unit_price: string}>
     *
     * @throws BusinessRuleException
     */
    private function saleItems(Collection $quoted, ?array $requested): array
    {
        $requested ??= $quoted->map(fn (ProformaItem $item) => ['product_id' => $item->product_id, 'quantity' => $item->quantity])->values()->all();

        return array_map(function (array $line) use ($quoted) {
            $item = $quoted->get($line['product_id']);

            if ($item === null) {
                throw new BusinessRuleException("Product {$line['product_id']} is not on the proforma. Edit the proforma to add it.");
            }

            if ($line['quantity'] > $item->quantity) {
                $sku = Product::withTrashed()->whereKey($item->product_id)->value('sku');

                throw new BusinessRuleException("Only {$item->quantity} of {$sku} were quoted; {$line['quantity']} requested.");
            }

            return ['product_id' => $item->product_id, 'quantity' => (int) $line['quantity'], 'unit_price' => $item->unit_price];
        }, $requested);
    }

    private function defaultNote(Proforma $proforma): string
    {
        $note = "From {$proforma->number}";

        return $proforma->customer_id === null && $proforma->customer_name ? "{$note} ({$proforma->customer_name})" : $note;
    }
}
