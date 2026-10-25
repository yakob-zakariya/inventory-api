<?php

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('holds a row lock that other connections must wait for', function () {
    $product = Product::factory()->create();

    config(['database.connections.mysql_second' => config('database.connections.mysql_testing')]);
    $second = DB::connection('mysql_second');
    $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $lockFromSecondConnection = fn () => $second->transaction(
        fn () => $second->table('products')->where('id', $product->id)->lockForUpdate()->first(),
    );

    // Nobody holds the lock yet, so the second connection gets the row at once.
    expect($lockFromSecondConnection())->not->toBeNull();

    DB::beginTransaction();

    try {
        app(StockLedger::class)->lockProducts([$product->id]);

        expect($lockFromSecondConnection)->toThrow(QueryException::class, 'Lock wait timeout');
    } finally {
        DB::rollBack();
        $second->disconnect();
    }
});

it('never oversells when two processes remove stock at the same time', function () {
    $product = Product::factory()->create(['sku' => 'RACE-1']);
    DB::transaction(fn () => app(StockLedger::class)->record($product, StockMovementType::Adjustment, 5, 'Opening stock'));

    $removeFour = function () use ($product) {
        DB::transaction(function () use ($product) {
            $ledger = app(StockLedger::class);
            $locked = $ledger->lockProducts([$product->id])->first();

            // Hold the lock long enough that, without it, both workers would read 5.
            usleep(300_000);

            $ledger->record($locked, StockMovementType::Adjustment, -4, 'Race');
        });

        return 'recorded';
    };

    $outcomes = collect(inParallel([$removeFour, $removeFour]))->sort()->values()->all();

    expect($outcomes)->toBe(['BusinessRuleException: Not enough stock for RACE-1: 1 on hand, 4 requested.', 'recorded'])
        ->and($product->currentStock())->toBe(1)
        ->and(StockMovement::where('product_id', $product->id)->pluck('quantity')->sort()->values()->all())->toBe([-4, 5]);
});
