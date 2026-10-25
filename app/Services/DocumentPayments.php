<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Support\Money;

/**
 * The payments sent with a new document, converted to cents and checked against its total.
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
     *
     * @throws BusinessRuleException when the payments exceed the document total
     */
    public static function fromInput(array $payments, int $documentTotalCents): self
    {
        $lines = array_map(fn (array $payment) => [
            'account_id' => (int) $payment['account_id'],
            'cents' => Money::toCents($payment['amount']),
            'receipt_reference' => $payment['receipt_reference'] ?? null,
        ], $payments);

        $paidCents = array_sum(array_column($lines, 'cents'));

        if ($paidCents > $documentTotalCents) {
            throw new BusinessRuleException(sprintf(
                'Payments (%s) exceed the document total (%s).',
                Money::fromCents($paidCents),
                Money::fromCents($documentTotalCents),
            ));
        }

        return new self($lines, $paidCents);
    }
}
