<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\ProformaStatus;
use App\Exceptions\ConflictException;
use App\Models\Product;
use App\Models\Proforma;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, edits and cancels proformas. Prices default to the product's selling price, using
 * the same pricing as sales; proformas never touch stock or money.
 */
class ProformaManager
{
    public function __construct(private DocumentNumberer $numberer) {}

    /**
     * @param  array{customer_id?: ?int, customer_name?: ?string, customer_phone?: ?string, proforma_date?: ?string,
     *     valid_until?: ?string, note?: ?string, items: list<array{product_id: int, quantity: int, unit_price?: string|int|float|null}>}  $data
     */
    public function create(array $data): Proforma
    {
        return DB::transaction(function () use ($data) {
            $date = $data['proforma_date'] ?? today()->toDateString();

            $proforma = Proforma::create([
                'number' => $this->numberer->next(DocumentType::Proforma),
                ...$this->customerChanges(new Proforma, $data),
                'proforma_date' => $date,
                'valid_until' => $data['valid_until'] ?? Carbon::parse($date)->addDays(7)->toDateString(),
                'total_amount' => '0.00',
                'note' => $data['note'] ?? null,
                'status' => ProformaStatus::Open,
            ]);

            $this->replaceItems($proforma, $data['items']);

            return $proforma;
        });
    }

    /**
     * Price items without saving, for the form.
     *
     * @param  list<array{product_id: int, quantity: int, unit_price?: string|int|float|null}>  $items
     */
    public function price(array $items): SaleDraft
    {
        return SaleDraft::make(['items' => $items], $this->products($items));
    }

    /**
     * Change an open, unexpired proforma. Items, when sent, replace the whole list.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ConflictException when the proforma is converted, cancelled or expired
     */
    public function update(Proforma $proforma, array $data): Proforma
    {
        return DB::transaction(function () use ($proforma, $data) {
            $proforma = $this->lockEditable($proforma);

            $proforma->fill([
                ...$this->customerChanges($proforma, $data),
                ...array_intersect_key($data, array_flip(['valid_until', 'note'])),
            ]);

            if ($proforma->customer_id === null && blank($proforma->customer_name)) {
                throw ValidationException::withMessages(['customer_name' => 'Choose a customer or type a name.']);
            }

            $proforma->save();

            if (array_key_exists('items', $data)) {
                $proforma->items()->delete();
                $this->replaceItems($proforma, $data['items']);
            }

            return $proforma;
        });
    }

    /**
     * @throws ConflictException when the proforma is converted, cancelled or expired
     */
    public function cancel(Proforma $proforma, string $reason): Proforma
    {
        return DB::transaction(function () use ($proforma, $reason) {
            $proforma = $this->lockEditable($proforma);

            $proforma->forceFill([
                'status' => ProformaStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancel_reason' => $reason,
            ])->save();

            return $proforma;
        });
    }

    /**
     * Lock the proforma and make sure it can still change.
     *
     * @throws ConflictException
     */
    public function lockEditable(Proforma $proforma): Proforma
    {
        $proforma = Proforma::query()->lockForUpdate()->findOrFail($proforma->id);

        if ($proforma->status === ProformaStatus::Converted) {
            throw new ConflictException("{$proforma->number} was converted to {$proforma->sale->number} and can no longer change.");
        }

        if ($proforma->status === ProformaStatus::Cancelled) {
            throw new ConflictException("{$proforma->number} is cancelled.");
        }

        if ($proforma->isExpired()) {
            throw new ConflictException("{$proforma->number} expired on {$proforma->valid_until->toDateString()}. Create a new proforma with current prices.");
        }

        return $proforma;
    }

    /**
     * The customer fields to change. Sending a customer replaces any typed name and phone;
     * sending a name or phone switches to a typed customer and keeps what was not sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function customerChanges(Proforma $proforma, array $data): array
    {
        if (! empty($data['customer_id'])) {
            return ['customer_id' => $data['customer_id'], 'customer_name' => null, 'customer_phone' => null];
        }

        if (array_key_exists('customer_id', $data) || array_key_exists('customer_name', $data) || array_key_exists('customer_phone', $data)) {
            return [
                'customer_id' => null,
                'customer_name' => array_key_exists('customer_name', $data) ? $data['customer_name'] : $proforma->customer_name,
                'customer_phone' => array_key_exists('customer_phone', $data) ? $data['customer_phone'] : $proforma->customer_phone,
            ];
        }

        return [];
    }

    /**
     * @param  list<array{product_id: int, quantity: int, unit_price?: string|int|float|null}>  $items
     */
    private function replaceItems(Proforma $proforma, array $items): void
    {
        $draft = $this->price($items);

        foreach ($draft->lines as $line) {
            $proforma->items()->create([
                'product_id' => $line['product']->id,
                'quantity' => $line['quantity'],
                'unit_price' => Money::fromCents($line['unit_price_cents']),
            ]);
        }

        $proforma->update(['total_amount' => Money::fromCents($draft->totalCents)]);
    }

    /**
     * @param  list<array{product_id: int}>  $items
     * @return Collection<int, Product> keyed by id
     */
    private function products(array $items): Collection
    {
        return Product::query()->findMany(array_column($items, 'product_id'))->keyBy('id');
    }
}
