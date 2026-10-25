<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * Locks the products on a new document's items.
 */
class DocumentProducts
{
    public function __construct(private StockLedger $stock) {}

    /**
     * Lock every product on the items (in id order) and make sure none was deleted meanwhile.
     *
     * @param  list<array{product_id: int}>  $items
     * @return Collection<int, Product> keyed by product id
     *
     * @throws BusinessRuleException
     */
    public function lock(array $items): Collection
    {
        $ids = array_map(fn (array $item) => (int) $item['product_id'], $items);
        $products = $this->stock->lockProducts($ids);

        foreach ($ids as $id) {
            if (! $products->has($id)) {
                throw new BusinessRuleException("Product {$id} is no longer available.");
            }
        }

        return $products;
    }
}
