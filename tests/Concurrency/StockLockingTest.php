<?php

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
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

    $resultsDir = storage_path('framework/testing/concurrency-'.uniqid());
    mkdir($resultsDir, recursive: true);
    $startAt = microtime(true) + 0.5;

    // Children must open their own connections, not share the parent's socket.
    DB::disconnect();

    $children = [];

    foreach ([1, 2] as $worker) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            $outcome = 'error';

            try {
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }

                DB::transaction(function () use ($product) {
                    $ledger = app(StockLedger::class);
                    $locked = $ledger->lockProducts([$product->id])->first();

                    // Hold the lock long enough that, without it, both workers would read 5.
                    usleep(300_000);

                    $ledger->record($locked, StockMovementType::Adjustment, -4, 'Race');
                });

                $outcome = 'recorded';
            } catch (BusinessRuleException) {
                $outcome = 'refused';
            } catch (Throwable $e) {
                $outcome = 'error: '.$e->getMessage();
            }

            file_put_contents("{$resultsDir}/{$worker}", $outcome);

            // Skip PHPUnit's shutdown handlers in the child.
            posix_kill(posix_getpid(), SIGKILL);
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $outcomes = collect([1, 2])->map(fn ($worker) => file_get_contents("{$resultsDir}/{$worker}"))->sort()->values()->all();
    array_map('unlink', glob("{$resultsDir}/*"));
    rmdir($resultsDir);

    DB::reconnect();

    expect($outcomes)->toBe(['recorded', 'refused'])
        ->and($product->currentStock())->toBe(1)
        ->and(StockMovement::where('product_id', $product->id)->pluck('quantity')->sort()->values()->all())->toBe([-4, 5]);
});
