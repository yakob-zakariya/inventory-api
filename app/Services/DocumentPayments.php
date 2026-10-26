<?php

namespace App\Services;

use App\Support\Money;

/**
 * The payments sent with a new document, converted to cents.
 */
final readonly class DocumentPayments
{
    /**
     * @param  list<array{account_id: int, cents: int, receipt_reference: ?string}>  $lines
     */
    private function __construct(
        public array $lines,
        public int $totalCents,
    ) {}

    /**
     * @param  list<array{account_id: int, amount: string|int|float, receipt_reference?: ?string}>  $payments
     */
    public static function fromInput(array $payments): self
    {
        $lines = array_map(fn (array $payment) => [
            'account_id' => (int) $payment['account_id'],
            'cents' => Money::toCents($payment['amount']),
            'receipt_reference' => $payment['receipt_reference'] ?? null,
        ], $payments);

        return new self($lines, array_sum(array_column($lines, 'cents')));
    }

    /**
     * The problem when the payments exceed the document total, if they do.
     */
    public function overpaymentOf(int $documentTotalCents): ?DocumentProblem
    {
        if ($this->totalCents <= $documentTotalCents) {
            return null;
        }

        return new DocumentProblem('overpaid', sprintf(
            'Payments (%s) exceed the document total (%s).',
            Money::fromCents($this->totalCents),
            Money::fromCents($documentTotalCents),
        ), 'payments');
    }
}
