<?php

use App\Enums\AccountTransactionType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\SaleRecorder;

beforeEach(function () {
    actingAsOwner();
    $this->cash = Account::factory()->create();
    $this->bank = Account::factory()->create();

    $product = Product::factory()->create(['selling_price' => '10.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 5]);
    $this->sale = app(SaleRecorder::class)->record([
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['account_id' => $this->cash->id, 'amount' => '10.00']],
    ]);

    $this->fuel = AccountTransaction::factory()->for($this->cash)->create(['type' => AccountTransactionType::Expense, 'amount' => '-50.00', 'note' => 'Fuel for the delivery truck']);
    $this->receipt = AccountTransaction::factory()->for($this->bank)->create(['type' => AccountTransactionType::Expense, 'amount' => '-20.00', 'note' => 'Bank charges', 'receipt_reference' => 'RCPT-4471']);
});

it('searches the ledger by note, receipt or document number', function (string $term, string $expected) {
    $ids = $this->getJson('/api/v1/account-transactions?search='.urlencode($term))->assertOk()->json('data.*.id');

    $expectedId = match ($expected) {
        'fuel' => $this->fuel->id,
        'receipt' => $this->receipt->id,
        'sale' => $this->sale->payments()->sole()->id,
    };

    expect($ids)->toBe([$expectedId]);
})->with([
    'note' => ['delivery truck', 'fuel'],
    'receipt reference' => ['4471', 'receipt'],
    'document number' => ['SAL-000001', 'sale'],
]);

it('combines search with the other filters', function () {
    $this->getJson("/api/v1/account-transactions?search=charges&account_id={$this->cash->id}")->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/account-transactions?search=charges&account_id={$this->bank->id}&type=expense")
        ->assertJsonPath('data.*.id', [$this->receipt->id]);
});

it('returns the whole ledger without a search', function () {
    $this->getJson('/api/v1/account-transactions')->assertJsonCount(3, 'data');
});
