<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * A sale priced and checked but not saved: what SaleRecorder saves and what a preview shows.
 * Prices default to the product's selling price; costs are the product's cost price now.
 */
final readonly class SaleDraft
{
    /**
     * @param  list<array{product: Product, quantity: int, unit_price_cents: int, unit_cost_cents: int}>  $lines
     * @param  list<DocumentProblem>  $problems  what would stop saving (stock is checked by the caller)
     */
    private function __construct(
        public array $lines,
        public int $totalCents,
        public DocumentPayments $payments,
        public array $problems,
    ) {}

    /**
     * @param  array{customer_id?: ?int, items: list<array{product_id: int, quantity: int, unit_price?: string|int|float|null}>,
     *     payments?: list<array{account_id: int, amount: string|int|float, receipt_reference?: ?string}>}  $data
     * @param  Collection<int, Product>  $products  keyed by id
     */
    public static function make(array $data, Collection $products): self
    {
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
        $payments = DocumentPayments::fromInput($data['payments'] ?? []);
        $problems = array_values(array_filter([$payments->overpaymentOf($totalCents)]));

        if ($payments->totalCents < $totalCents && empty($data['customer_id'])) {
            $problems[] = new DocumentProblem('walk_in_not_paid', 'A customer is required for a sale that is not fully paid.');
        }

        return new self($lines, $totalCents, $payments, $problems);
    }

    /**
     * @throws BusinessRuleException with the first problem, as saving reports it
     */
    public function assertSavable(): void
    {
        if ($this->problems !== []) {
            throw $this->problems[0]->toException();
        }
    }

    public function paymentStatus(): PaymentStatus
    {
        return PaymentStatus::forCents($this->payments->totalCents, $this->totalCents);
    }
}
