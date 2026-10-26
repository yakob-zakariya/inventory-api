<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * Prices and checks a new sale or purchase without saving anything, so a form can show totals
 * and problems while it is filled in. Stock is read without locks: saving checks it again.
 */
class DocumentPreviewer
{
    /**
     * @param  array{customer_id?: ?int, items: list<array{product_id: int, quantity: int, unit_price?: string|int|float|null}>,
     *     payments?: list<array{account_id: int, amount: string|int|float, receipt_reference?: ?string}>}  $data
     *
     * @throws BusinessRuleException for the same problems saving would report
     */
    public function sale(array $data): SaleDraft
    {
        $draft = SaleDraft::make($data, $this->products($data['items']));

        foreach ($draft->lines as $line) {
            $onHand = (int) $line['product']->stock;

            if ($onHand < $line['quantity']) {
                throw StockLedger::notEnoughStock($line['product'], $onHand, $line['quantity']);
            }
        }

        return $draft;
    }

    /**
     * @param  array{items: list<array{product_id: int, quantity: int, unit_cost: string|int|float}>,
     *     payments?: list<array{account_id: int, amount: string|int|float, receipt_reference?: ?string}>}  $data
     *
     * @throws BusinessRuleException when the payments exceed the total
     */
    public function purchase(array $data): PurchaseDraft
    {
        return PurchaseDraft::make($data, $this->products($data['items']));
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
