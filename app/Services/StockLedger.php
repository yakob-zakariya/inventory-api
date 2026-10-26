<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only writer of the stock ledger. Every method must run inside DB::transaction,
 * after the products involved have been locked with lockProducts().
 */
class StockLedger
{
    /**
     * Lock the given products for the rest of the transaction, in id order to avoid deadlocks.
     *
     * @param  array<int, int>  $productIds
     * @return Collection<int, Product> keyed by product id
     */
    public function lockProducts(array $productIds): Collection
    {
        $this->ensureInTransaction();

        return Product::query()
            ->whereKey(array_unique($productIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Current stock of a product. Read it only after locking the product.
     */
    public function stockOf(Product $product): int
    {
        return $product->currentStock();
    }

    /**
     * Append a movement, refusing any that would take stock below zero.
     *
     * @throws BusinessRuleException
     */
    public function record(
        Product $product,
        StockMovementType $type,
        int $quantity,
        ?string $note = null,
        ?Model $reference = null,
    ): StockMovement {
        $this->ensureInTransaction();

        if ($quantity === 0) {
            throw new LogicException('A stock movement needs a non-zero quantity.');
        }

        if ($quantity < 0) {
            $onHand = $this->stockOf($product);

            if ($onHand + $quantity < 0) {
                throw static::notEnoughStock($product, $onHand, -$quantity);
            }
        }

        return StockMovement::create([
            'product_id' => $product->id,
            'type' => $type,
            'quantity' => $quantity,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'note' => $note,
        ]);
    }

    /**
     * The error for taking more stock than is on hand.
     */
    public static function notEnoughStock(Product $product, int $onHand, int $requested): BusinessRuleException
    {
        return new BusinessRuleException(sprintf(
            'Not enough stock for %s: %d on hand, %d requested.',
            $product->sku,
            $onHand,
            $requested,
        ));
    }

    private function ensureInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock changes must run inside a database transaction.');
        }
    }
}
