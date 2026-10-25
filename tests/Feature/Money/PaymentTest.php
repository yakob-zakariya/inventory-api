<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseRecorder;
use App\Services\SaleRecorder;
use Laravel\Sanctum\Sanctum;

/**
 * An unpaid credit sale of 1000.00.
 */
function creditSale(): Sale
{
    $product = Product::factory()->create(['selling_price' => '500.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 10]);

    return app(SaleRecorder::class)->record([
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ]);
}

/**
 * An unpaid purchase of 300.00.
 */
function creditPurchase(): Purchase
{
    return app(PurchaseRecorder::class)->record([
        'supplier_id' => Supplier::factory()->create()->id,
        'items' => [['product_id' => Product::factory()->create()->id, 'quantity' => 3, 'unit_cost' => '100.00']],
    ]);
}

it('takes a customer payment in parts until the sale is paid', function () {
    actingAsOwner();
    $sale = creditSale();
    $cash = Account::factory()->create(['opening_balance' => '0.00']);

    $this->postJson('/api/v1/payments', [
        'document_type' => 'sale', 'document_id' => $sale->id, 'account_id' => $cash->id, 'amount' => '400.00',
    ])
        ->assertCreated()
        ->assertJsonPath('data.number', $sale->number)
        ->assertJsonPath('data.paid_amount', '400.00')
        ->assertJsonPath('data.balance_due', '600.00')
        ->assertJsonPath('data.payment_status', 'partial');

    $this->postJson('/api/v1/payments', [
        'document_type' => 'sale', 'document_id' => $sale->id, 'account_id' => $cash->id,
        'amount' => 600, 'transaction_date' => now()->subDay()->toDateString(), 'receipt_reference' => 'RCPT-9',
    ])
        ->assertCreated()
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonCount(2, 'data.payments')
        ->assertJsonPath('data.payments.1.receipt_reference', 'RCPT-9');

    expect($cash->balanceCents())->toBe(100000);
});

it('pays a supplier as money out of the account', function () {
    actingAsOwner();
    $purchase = creditPurchase();
    $bank = Account::factory()->create(['opening_balance' => '1000.00']);

    $this->postJson('/api/v1/payments', [
        'document_type' => 'purchase', 'document_id' => $purchase->id, 'account_id' => $bank->id, 'amount' => '300.00',
    ])
        ->assertCreated()
        ->assertJsonPath('data.number', $purchase->number)
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.payments.0.amount', '300.00');

    expect(AccountTransaction::sole()->amount)->toBe('-300.00')
        ->and($bank->balanceCents())->toBe(70000);
});

it('refuses a payment above the balance due', function () {
    actingAsOwner();
    $sale = creditSale();
    $cash = Account::factory()->create();

    $this->postJson('/api/v1/payments', [
        'document_type' => 'sale', 'document_id' => $sale->id, 'account_id' => $cash->id, 'amount' => '1000.01',
    ])
        ->assertUnprocessable()
        ->assertExactJson(['message' => "Payment (1000.01) exceeds the balance due on {$sale->number} (1000.00)."]);

    expect(AccountTransaction::count())->toBe(0);
});

it('refuses a payment on a voided document', function () {
    actingAsOwner();
    $sale = creditSale();
    // Voiding arrives in phase 6; mark the sale voided directly for now.
    Sale::whereKey($sale->id)->update(['status' => 'voided']);
    $cash = Account::factory()->create();

    $this->postJson('/api/v1/payments', [
        'document_type' => 'sale', 'document_id' => $sale->id, 'account_id' => $cash->id, 'amount' => '10.00',
    ])
        ->assertConflict()
        ->assertExactJson(['message' => "{$sale->number} is voided and cannot take payments."]);
});

it('lets staff take payments for sales but not pay suppliers', function () {
    actingAsStaff();
    $sale = creditSale();
    $purchase = creditPurchase();
    $cash = Account::factory()->create();

    $this->postJson('/api/v1/payments', [
        'document_type' => 'sale', 'document_id' => $sale->id, 'account_id' => $cash->id, 'amount' => '10.00',
    ])
        ->assertCreated()
        ->assertJsonMissingPath('data.items.0.unit_cost');

    $this->postJson('/api/v1/payments', [
        'document_type' => 'purchase', 'document_id' => $purchase->id, 'account_id' => $cash->id, 'amount' => '10.00',
    ])->assertForbidden();
});

it('validates a payment', function (Closure $overrides, string $field) {
    actingAsOwner();
    $sale = creditSale();

    $this->postJson('/api/v1/payments', [
        'document_type' => 'sale',
        'document_id' => $sale->id,
        'account_id' => Account::factory()->create()->id,
        'amount' => '10.00',
        ...$overrides(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown document type' => [fn () => ['document_type' => 'invoice'], 'document_type'],
    'unknown sale' => [fn () => ['document_id' => 999], 'document_id'],
    'inactive account' => [fn () => ['account_id' => Account::factory()->inactive()->create()->id], 'account_id'],
    'zero amount' => [fn () => ['amount' => 0], 'amount'],
    'three decimals' => [fn () => ['amount' => '1.001'], 'amount'],
    'future date' => [fn () => ['transaction_date' => now()->addDay()->toDateString()], 'transaction_date'],
]);

it('requires payments.record', function () {
    $this->postJson('/api/v1/payments', [])->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/payments', [])->assertForbidden();
});
