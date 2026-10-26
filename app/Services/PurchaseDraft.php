<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * A purchase priced and checked but not saved: what PurchaseRecorder saves and what a preview shows.
 */
final readonly class PurchaseDraft
{
    /**
     * @param  list<array{product: Product, quantity: int, unit_cost_cents: int}>  $lines
     */
    private function __construct(
        public array $lines,
        public int $totalCents,
        public DocumentPayments $payments,
    ) {}

    /**
     * @param  array{items: list<array{product_id: int, quantity: int, unit_cost: string|int|float}>,
     *     payments?: list<array{account_id: int, amount: string|int|float, receipt_reference?: ?string}>}  $data
     * @param  Collection<int, Product>  $products  keyed by id
     *
     * @throws BusinessRuleException when the payments exceed the total
     */
    public static function make(array $data, Collection $products): self
    {
        $lines = array_map(fn (array $item) => [
            'product' => $products[$item['product_id']],
            'quantity' => (int) $item['quantity'],
            'unit_cost_cents' => Money::toCents($item['unit_cost']),
        ], $data['items']);

        $totalCents = array_sum(array_map(fn (array $line) => $line['quantity'] * $line['unit_cost_cents'], $lines));

        return new self($lines, $totalCents, DocumentPayments::fromInput($data['payments'] ?? [], $totalCents));
    }

    public function paymentStatus(): PaymentStatus
    {
        return PaymentStatus::forCents($this->payments->totalCents, $this->totalCents);
    }
}
