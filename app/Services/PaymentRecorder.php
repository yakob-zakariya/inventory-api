<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\DocumentStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\Purchase;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Records a later payment against an existing purchase or sale.
 */
class PaymentRecorder
{
    public function __construct(private MoneyLedger $money) {}

    /**
     * @param  array{document_type: string, document_id: int, account_id: int, amount: string|int|float,
     *     transaction_date?: ?string, receipt_reference?: ?string, note?: ?string}  $data
     *
     * @throws ConflictException when the document is voided
     * @throws BusinessRuleException when the payment exceeds the balance due
     */
    public function record(array $data): Sale|Purchase
    {
        return DB::transaction(function () use ($data) {
            $documentClass = $data['document_type'] === 'sale' ? Sale::class : Purchase::class;

            // Lock the document so two payments cannot both fit the same balance due.
            $document = $documentClass::query()->lockForUpdate()->findOrFail($data['document_id']);

            if ($document->status === DocumentStatus::Voided) {
                throw new ConflictException("{$document->number} is voided and cannot take payments.");
            }

            $cents = Money::toCents($data['amount']);
            $balanceDue = $document->balanceDueCents();

            if ($cents > $balanceDue) {
                throw new BusinessRuleException(sprintf(
                    'Payment (%s) exceeds the balance due on %s (%s).',
                    Money::fromCents($cents),
                    $document->number,
                    Money::fromCents($balanceDue),
                ));
            }

            $this->money->record(
                Account::findOrFail($data['account_id']),
                $document instanceof Sale ? AccountTransactionType::SalePayment : AccountTransactionType::PurchasePayment,
                $cents * $document::paymentSign(),
                $data['transaction_date'] ?? now()->toDateString(),
                $data['note'] ?? null,
                $document,
                $data['receipt_reference'] ?? null,
            );

            return $document;
        });
    }
}
