<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\BusinessSummary;
use App\Services\PaymentRecorder;
use App\Services\SaleRecorder;

it('lets only one of two racing payments use up the same balance due', function () {
    $product = Product::factory()->create(['selling_price' => '100.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 5]);
    $sale = app(SaleRecorder::class)->record([
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ]);
    $cash = Account::factory()->create();

    $payEighty = function () use ($sale, $cash) {
        // Pause after the document is locked and before the balance due is read.
        Sale::retrieved(fn () => usleep(300_000));

        app(PaymentRecorder::class)->record([
            'document_type' => 'sale', 'document_id' => $sale->id, 'account_id' => $cash->id, 'amount' => '80.00',
        ]);

        return 'paid';
    };

    $outcomes = collect(inParallel([$payEighty, $payEighty]))->sort()->values()->all();

    expect($outcomes)->toBe(["BusinessRuleException: Payment (80.00) exceeds the balance due on {$sale->number} (20.00).", 'paid'])
        ->and(AccountTransaction::where('reference_id', $sale->id)->sum('amount'))->toEqual('80.00');
});

it('calculates the business summary in MySQL as it does in SQLite', function () {
    $product = Product::factory()->create(['cost_price' => '0.10', 'selling_price' => '0.30']);
    StockMovement::factory()->for($product)->create(['quantity' => 3]);
    Account::factory()->create(['opening_balance' => '0.20']);

    $summary = BusinessSummary::now();

    expect($summary->inventoryAtCostCents)->toBe(30)
        ->and($summary->inventoryAtSellingPriceCents)->toBe(90)
        ->and($summary->netWorthCents())->toBe(50);
});
