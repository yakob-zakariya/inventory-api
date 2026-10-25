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
            $products = $this->products->lock($data['items']);

            $lines = array_map(function (array $item) use ($products) {
                $product = $products[$item['product_id']];

                return [
                    'product' => $product,
                    'quantity' => (int) $item['quantity'],
                    'unit_price_cents' => Money::toCents($item['unit_price'] ?? $product->selling_price),
                    'unit_cost_cents' => Money::toCents($product->cost_price),
                ];
            }, $data['items']);

            $totalCents = array_sum(array_map(fn (array $line) => $line['quantity'] * $line['unit_price_cents'], $lines));
            $payments = DocumentPayments::fromInput($data['payments'] ?? [], $totalCents);

            if ($payments->totalCents < $totalCents && empty($data['customer_id'])) {
                throw new BusinessRuleException('A customer is required for a sale that is not fully paid.');
            }

            $sale = Sale::create([
                'number' => $this->numberer->next(DocumentType::Sale),
                'customer_id' => $data['customer_id'] ?? null,
                'sale_date' => $data['sale_date'] ?? now()->toDateString(),
                'total_amount' => Money::fromCents($totalCents),
                'note' => $data['note'] ?? null,
                'status' => DocumentStatus::Active,
            ]);

            foreach ($lines as $line) {
                $sale->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => Money::fromCents($line['unit_price_cents']),
                    'unit_cost' => Money::fromCents($line['unit_cost_cents']),
                ]);

                $this->stock->record($line['product'], StockMovementType::Sale, -$line['quantity'], reference: $sale);
            }

            foreach ($payments->lines as $payment) {
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
