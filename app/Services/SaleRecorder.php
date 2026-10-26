<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Records a sale with its items, stock movements and initial payments in one transaction.
 */
class SaleRecorder
{
    public function __construct(
        private StockLedger $stock,
        private MoneyLedger $money,
        private DocumentNumberer $numberer,
        private DocumentProducts $products,
    ) {}

    /**
     * @param  array{customer_id?: ?int, sale_date?: ?string, note?: ?string,
     *     items: list<array{product_id: int, quantity: int, unit_price?: string|int|float|null}>,
     *     payments?: list<array{account_id: int, amount: string|int|float, receipt_reference?: ?string}>}  $data
     *
     * @throws BusinessRuleException for missing stock, overpayment, or credit without a customer
     */
    public function record(array $data): Sale
    {
        return DB::transaction(function () use ($data) {
            $draft = SaleDraft::make($data, $this->products->lock($data['items']));

            $sale = Sale::create([
                'number' => $this->numberer->next(DocumentType::Sale),
                'customer_id' => $data['customer_id'] ?? null,
                'sale_date' => $data['sale_date'] ?? now()->toDateString(),
                'total_amount' => Money::fromCents($draft->totalCents),
                'note' => $data['note'] ?? null,
                'status' => DocumentStatus::Active,
            ]);

            foreach ($draft->lines as $line) {
                $sale->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => Money::fromCents($line['unit_price_cents']),
                    'unit_cost' => Money::fromCents($line['unit_cost_cents']),
                ]);

                $this->stock->record($line['product'], StockMovementType::Sale, -$line['quantity'], reference: $sale);
            }

            foreach ($draft->payments->lines as $payment) {
                $this->money->record(
                    Account::findOrFail($payment['account_id']),
                    AccountTransactionType::SalePayment,
                    $payment['cents'],
                    $sale->sale_date,
                    reference: $sale,
                    receiptReference: $payment['receipt_reference'],
                );
            }

            return $sale;
        });
    }
}
