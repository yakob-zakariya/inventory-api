<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * Prices and checks a new sale or purchase without saving anything, so a form can show totals
 * while it is filled in. Business rules that would stop saving are listed, not thrown.
 * Stock is read without locks: saving checks it again.
 */
class DocumentPreviewer
{
    /**
     * @param  array{customer_id?: ?int, items: list<array{product_id: int, quantity: int, unit_price?: string|int|float|null}>,
     *     payments?: list<array{account_id: int, amount: string|int|float, receipt_reference?: ?string}>}  $data
     * @return array{draft: SaleDraft, problems: list<DocumentProblem>}
     */
    public function sale(array $data): array
    {
        $draft = SaleDraft::make($data, $this->products($data['items']));
        $problems = $draft->problems;

        foreach ($draft->lines as $index => $line) {
            $onHand = (int) $line['product']->stock;

            if ($onHand < $line['quantity']) {
                $message = StockLedger::notEnoughStock($line['product'], $onHand, $line['quantity'])->getMessage();
                $problems[] = new DocumentProblem('insufficient_stock', $message, "items.{$index}.quantity");
            }
        }

        return ['draft' => $draft, 'problems' => $problems];
    }

    /**
     * @param  array{items: list<array{product_id: int, quantity: int, unit_cost: string|int|float}>,
     *     payments?: list<array{account_id: int, amount: string|int|float, receipt_reference?: ?string}>}  $data
     * @return array{draft: PurchaseDraft, problems: list<DocumentProblem>}
     */
    public function purchase(array $data): array
    {
        $draft = PurchaseDraft::make($data, $this->products($data['items']));

        return ['draft' => $draft, 'problems' => $draft->problems];
    }

    /**
     * @param  list<array{product_id: int}>  $items
     * @return Collection<int, Product> keyed by id, with stock loaded
     */
    private function products(array $items): Collection
    {
        return Product::query()->withStock()->findMany(array_column($items, 'product_id'))->keyBy('id');
    }
}
