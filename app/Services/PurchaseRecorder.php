<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\Purchase;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Records a purchase with its items, stock movements and initial payments in one transaction.
 */
class PurchaseRecorder
{
    public function __construct(
        private StockLedger $stock,
        private MoneyLedger $money,
        private DocumentNumberer $numberer,
        private DocumentProducts $products,
    ) {}

    /**
     * @param  array{supplier_id: int, invoice_number?: ?string, purchase_date?: ?string, note?: ?string,
     *     items: list<array{product_id: int, quantity: int, unit_cost: string|int|float}>,
     *     payments?: list<array{account_id: int, amount: string|int|float, receipt_reference?: ?string}>}  $data
     *
     * @throws BusinessRuleException
     */
    public function record(array $data): Purchase
    {
        return DB::transaction(function () use ($data) {
            $draft = PurchaseDraft::make($data, $this->products->lock($data['items']));
            $draft->assertSavable();
            $number = $this->numberer->next(DocumentType::Purchase);

            $purchase = Purchase::create([
                'number' => $number,
                'supplier_id' => $data['supplier_id'],
                'invoice_number' => $data['invoice_number'] ?? null,
                'purchase_date' => $data['purchase_date'] ?? now()->toDateString(),
                'total_amount' => Money::fromCents($draft->totalCents),
                'note' => $data['note'] ?? null,
                'status' => DocumentStatus::Active,
            ]);

            foreach ($draft->lines as $line) {
                $purchase->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_cost' => Money::fromCents($line['unit_cost_cents']),
                    'previous_cost_price' => $line['product']->cost_price,
                ]);

                $this->stock->record($line['product'], StockMovementType::Purchase, $line['quantity'], reference: $purchase);

                // The latest purchase cost becomes the product's cost for future sales.
                $line['product']->update(['cost_price' => Money::fromCents($line['unit_cost_cents'])]);
            }

            foreach ($draft->payments->lines as $payment) {
                $this->money->record(
                    Account::findOrFail($payment['account_id']),
                    AccountTransactionType::PurchasePayment,
                    -$payment['cents'],
                    $purchase->purchase_date,
                    reference: $purchase,
                    receiptReference: $payment['receipt_reference'],
                );
            }

            return $purchase;
        });
    }
}
