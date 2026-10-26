<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\DocumentVoider;
use App\Services\MoneyLedger;
use App\Services\PaymentRecorder;
use App\Services\PurchaseRecorder;
use App\Services\SaleRecorder;
use Illuminate\Support\Facades\DB;

it('lets either a void or a payment win on the same sale, never both', function () {
    $product = Product::factory()->create(['selling_price' => '100.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 5]);
    $sale = app(SaleRecorder::class)->record([
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ]);
    $cash = Account::factory()->create();

    // Both pause after reading the sale, before checking its payments or status.
    $pay = function () use ($sale, $cash) {
        Sale::retrieved(fn () => usleep(300_000));
        app(PaymentRecorder::class)->record([
            'document_type' => 'sale', 'document_id' => $sale->id, 'account_id' => $cash->id, 'amount' => '100.00',
        ]);

        return 'paid';
    };
    $void = function () use ($sale) {
        Sale::retrieved(fn () => usleep(300_000));
        app(DocumentVoider::class)->void($sale, 'Cancelled');

        return 'voided';
    };

    [$paid, $voided] = inParallel([$pay, $void]);
    $sale = $sale->fresh();

    expect([$paid, $voided])->toBeIn([
        ['paid', "BusinessRuleException: {$sale->number} has payments of 100.00. Reverse them before voiding it."],
        ["ConflictException: {$sale->number} is voided and cannot take payments.", 'voided'],
    ])->and($sale->status->value === 'voided')->toBe($sale->paidCents() === 0);
});

it('reverses an entry only once when two reversals race', function () {
    $entry = AccountTransaction::factory()->create();

    $reverse = function () use ($entry) {
        // Pause after the account is locked, before checking for an earlier reversal.
        Account::retrieved(fn () => usleep(300_000));
        DB::transaction(fn () => app(MoneyLedger::class)->reverse($entry, 'Mistake', now()->toDateString()));

        return 'reversed';
    };

    $outcomes = collect(inParallel([$reverse, $reverse]))->sort()->values()->all();

    expect($outcomes)->toBe(["ConflictException: Transaction {$entry->id} is already reversed.", 'reversed'])
        ->and(AccountTransaction::where('reverses_transaction_id', $entry->id)->count())->toBe(1);
});

it('never takes stock below zero when a purchase void races a sale of its goods', function () {
    $product = Product::factory()->create(['selling_price' => '10.00']);
    $purchase = app(PurchaseRecorder::class)->record([
        'supplier_id' => Supplier::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '5.00']],
    ]);
    $customerId = Customer::factory()->create()->id;

    // Both pause after locking the product, before checking its stock.
    $void = function () use ($purchase) {
        Product::retrieved(fn () => usleep(300_000));
        app(DocumentVoider::class)->void($purchase, 'Wrong supplier');

        return 'voided';
    };
    $sell = function () use ($product, $customerId) {
        Product::retrieved(fn () => usleep(300_000));
        app(SaleRecorder::class)->record([
            'customer_id' => $customerId,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ]);

        return 'sold';
    };

    [$voided, $sold] = inParallel([$void, $sell]);

    expect([$voided, $sold])->toBeIn([
        ['voided', "BusinessRuleException: Not enough stock for {$product->sku}: 0 on hand, 3 requested."],
        ["BusinessRuleException: Not enough stock for {$product->sku}: 2 on hand, 5 requested.", 'sold'],
    ])->and($product->currentStock())->toBeGreaterThanOrEqual(0);

    expect(Purchase::find($purchase->id)->status->value === 'voided')->toBe($voided === 'voided');
});
