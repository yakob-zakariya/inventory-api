<?php

use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Services\SaleRecorder;
use App\Services\StockLedger;
use Illuminate\Support\Facades\DB;

function stockUp(Product $product, int $quantity): void
{
    DB::transaction(fn () => app(StockLedger::class)->record($product, StockMovementType::Adjustment, $quantity, 'Opening stock'));
}

/**
 * A worker that sells on credit, pausing while the sale is created (after the products
 * are locked, before stock is checked) so that racing workers overlap.
 */
function slowSale(int $productId, int $quantity, int $customerId): Closure
{
    return function () use ($productId, $quantity, $customerId) {
        Sale::creating(fn () => usleep(300_000));

        return app(SaleRecorder::class)->record([
            'customer_id' => $customerId,
            'items' => [['product_id' => $productId, 'quantity' => $quantity]],
        ])->number;
    };
}

it('sells the last units to only one of two racing sales', function () {
    $product = Product::factory()->create(['sku' => 'LAST-5']);
    stockUp($product, 5);
    $customer = Customer::factory()->create();

    $outcomes = inParallel([
        slowSale($product->id, 4, $customer->id),
        slowSale($product->id, 4, $customer->id),
    ]);

    expect(collect($outcomes)->sort()->values()->all())
        ->toBe(['BusinessRuleException: Not enough stock for LAST-5: 1 on hand, 4 requested.', 'SAL-000001'])
        ->and($product->currentStock())->toBe(1)
        ->and(Sale::count())->toBe(1);
});

it('gives racing sales of different products distinct, consecutive numbers', function () {
    $cement = Product::factory()->create();
    $paint = Product::factory()->create();
    stockUp($cement, 10);
    stockUp($paint, 10);
    $customer = Customer::factory()->create();

    $outcomes = inParallel([
        slowSale($cement->id, 1, $customer->id),
        slowSale($paint->id, 1, $customer->id),
    ]);

    expect(collect($outcomes)->sort()->values()->all())->toBe(['SAL-000001', 'SAL-000002'])
        ->and(Sale::pluck('number')->sort()->values()->all())->toBe(['SAL-000001', 'SAL-000002']);
});
