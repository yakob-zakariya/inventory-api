<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Voids a purchase or sale: reverses its stock movements and marks it voided.
 * The document stays visible; voided documents are left out of totals and reports.
 */
class DocumentVoider
{
    public function __construct(
        private StockLedger $stock,
        private DocumentProducts $products,
    ) {}

    /**
     * @throws ConflictException when the document is already voided
     * @throws BusinessRuleException when it still has payments or the stock cannot be reversed
     */
    public function void(Purchase|Sale $document, string $reason): Purchase|Sale
    {
        // Items never change, so they are read before the transaction. Inside it, the first plain read
        // fixes MySQL's snapshot, and the stock and payment checks must see what committed while waiting for the locks.
        $items = $document->items()->orderBy('id')->get();

        return DB::transaction(function () use ($document, $items, $reason) {
            $products = $this->products->lock($items->map(fn ($item) => ['product_id' => $item->product_id])->all());

            $document = $document::query()->lockForUpdate()->findOrFail($document->getKey());

            if ($document->status === DocumentStatus::Voided) {
                throw new ConflictException("{$document->number} is already voided.");
            }

            $paidCents = $document->paidCents();

            if ($paidCents !== 0) {
                throw new BusinessRuleException(sprintf(
                    '%s has payments of %s. Reverse them before voiding it.',
                    $document->number,
                    Money::fromCents($paidCents),
                ));
            }

            // A purchase brought stock in, so its void takes it out again (and must pass the stock rule).
            $sign = $document instanceof Purchase ? -1 : 1;

            foreach ($items as $item) {
                $this->stock->record($products[$item->product_id], StockMovementType::Reversal, $sign * $item->quantity, $reason, $document);
            }

            if ($document instanceof Purchase) {
                $this->restoreCosts($document, $items, $products);
            }

            $document->forceFill([
                'status' => DocumentStatus::Voided,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            return $document;
        });
    }

    /**
     * Put back the cost each product had before this purchase, unless something changed it since:
     * a later active purchase of the product, or a manual edit (the cost no longer matches).
     *
     * @param  Collection<int, PurchaseItem>  $items
     * @param  Collection<int, Product>  $products  locked, keyed by id
     */
    private function restoreCosts(Purchase $purchase, Collection $items, Collection $products): void
    {
        foreach ($items as $item) {
            $product = $products[$item->product_id];

            if ($item->previous_cost_price === null || $product->cost_price !== $item->unit_cost) {
                continue;
            }

            $boughtSince = PurchaseItem::query()
                ->where('product_id', $product->id)
                ->where('purchase_id', '>', $purchase->id)
                ->whereRelation('purchase', 'status', DocumentStatus::Active->value)
                ->exists();

            if (! $boughtSince) {
                $product->update(['cost_price' => $item->previous_cost_price]);
            }
        }
    }
}
