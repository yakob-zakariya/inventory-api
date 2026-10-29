<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Proforma;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\ProformaConverter;
use App\Services\ProformaManager;
use App\Services\SaleRecorder;

it('converts a proforma only once when two cashiers race', function () {
    $product = Product::factory()->create(['selling_price' => '10.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 20]);
    $proforma = app(ProformaManager::class)->create([
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ]);

    $convert = function () use ($proforma) {
        // Pause after the proforma is locked, before its status is checked.
        Proforma::retrieved(fn () => usleep(300_000));
        app(ProformaConverter::class)->convert($proforma, []);

        return 'converted';
    };

    $outcomes = collect(inParallel([$convert, $convert]))->sort()->values()->all();

    expect($outcomes)->toBe(["ConflictException: {$proforma->number} was already converted to a sale.", 'converted'])
        ->and(Sale::count())->toBe(1)
        ->and($product->currentStock())->toBe(18);
});

it('never oversells when a conversion races a sale for the last stock', function () {
    $product = Product::factory()->create(['sku' => 'CEM-50', 'selling_price' => '10.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 5]);
    $customerId = Customer::factory()->create()->id;
    $proforma = app(ProformaManager::class)->create([
        'customer_id' => $customerId,
        'items' => [['product_id' => $product->id, 'quantity' => 4]],
    ]);

    // Both pause after locking the product, before checking its stock.
    $convert = function () use ($proforma) {
        Product::retrieved(fn () => usleep(300_000));
        app(ProformaConverter::class)->convert($proforma, []);

        return 'converted';
    };
    $sell = function () use ($product, $customerId) {
        Product::retrieved(fn () => usleep(300_000));
        app(SaleRecorder::class)->record(['customer_id' => $customerId, 'items' => [['product_id' => $product->id, 'quantity' => 3]]]);

        return 'sold';
    };

    [$converted, $sold] = inParallel([$convert, $sell]);

    expect([$converted, $sold])->toBeIn([
        ['converted', 'BusinessRuleException: Not enough stock for CEM-50: 1 on hand, 3 requested.'],
        ['BusinessRuleException: Not enough stock for CEM-50: 2 on hand, 4 requested.', 'sold'],
    ])->and($product->currentStock())->toBeGreaterThanOrEqual(0);
});
