<?php

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockLedger;
use Illuminate\Support\Facades\DB;

/*
 * RefreshDatabase wraps every test in a transaction, so DB::transactionLevel() is
 * already 1 here. The "outside a transaction" tests commit that level away first.
 */

it('records movements and calculates stock from them', function () {
    $ledger = app(StockLedger::class);
    $product = Product::factory()->create();

    DB::transaction(function () use ($ledger, $product) {
        $ledger->record($product, StockMovementType::Adjustment, 10, 'Opening stock');
        $ledger->record($product, StockMovementType::Adjustment, -4, 'Damaged');
    });

    expect($product->currentStock())->toBe(6)
        ->and(StockMovement::count())->toBe(2);
});

it('refuses a movement that would make stock negative', function () {
    $ledger = app(StockLedger::class);
    $product = Product::factory()->create(['sku' => 'CEM-50']);
    StockMovement::factory()->for($product)->create(['quantity' => 3]);

    expect(fn () => DB::transaction(fn () => $ledger->record($product, StockMovementType::Adjustment, -5, 'Too many')))
        ->toThrow(BusinessRuleException::class, 'Not enough stock for CEM-50: 3 on hand, 5 requested.');

    expect($product->currentStock())->toBe(3);
});

it('allows taking stock down to exactly zero', function () {
    $ledger = app(StockLedger::class);
    $product = Product::factory()->create();
    StockMovement::factory()->for($product)->create(['quantity' => 3]);

    DB::transaction(fn () => $ledger->record($product, StockMovementType::Adjustment, -3, 'Sold out'));

    expect($product->currentStock())->toBe(0);
});

it('refuses a zero quantity', function () {
    $product = Product::factory()->create();

    expect(fn () => DB::transaction(fn () => app(StockLedger::class)->record($product, StockMovementType::Adjustment, 0)))
        ->toThrow(LogicException::class);
});

it('locks products and returns them keyed by id', function () {
    $products = Product::factory()->count(3)->create();
    $ids = $products->pluck('id')->reverse()->values()->all();

    $locked = DB::transaction(fn () => app(StockLedger::class)->lockProducts([...$ids, $ids[0]]));

    expect($locked->keys()->all())->toBe($products->pluck('id')->sort()->values()->all());
});

it('refuses to work outside a transaction', function () {
    $product = Product::factory()->make(['id' => 1]);
    DB::commit();

    try {
        expect(fn () => app(StockLedger::class)->record($product, StockMovementType::Adjustment, 1))
            ->toThrow(LogicException::class, 'inside a database transaction')
            ->and(fn () => app(StockLedger::class)->lockProducts([$product->id]))
            ->toThrow(LogicException::class, 'inside a database transaction');
    } finally {
        DB::beginTransaction();
    }
});

it('never lets a movement be updated or deleted', function () {
    $movement = StockMovement::factory()->create(['quantity' => 5]);

    expect(fn () => $movement->update(['quantity' => 50]))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $movement->delete())->toThrow(LogicException::class, 'append-only');

    expect($movement->fresh()->quantity)->toBe(5);
});
