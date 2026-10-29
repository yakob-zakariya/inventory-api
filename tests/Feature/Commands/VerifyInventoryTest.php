<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Real history through the API: a paid purchase, a paid sale, a voided credit sale, a
 * transfer and a reversed transfer.
 */
beforeEach(function () {
    actingAsOwner();
    $this->cash = Account::factory()->create(['name' => 'Cash', 'opening_balance' => '5000.00']);
    $this->bank = Account::factory()->create(['name' => 'Bank', 'opening_balance' => '0.00']);
    $this->product = Product::factory()->create(['sku' => 'CEM-50', 'selling_price' => '720.00']);
    $customer = Customer::factory()->create();

    $this->postJson('/api/v1/purchases', [
        'supplier_id' => Supplier::factory()->create()->id,
        'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => '650.00']],
        'payments' => [['account_id' => $this->cash->id, 'amount' => '1000.00']],
    ])->assertCreated();
    $this->sale = Sale::find($this->postJson('/api/v1/sales', [
        'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        'payments' => [['account_id' => $this->cash->id, 'amount' => '1440.00']],
    ])->assertCreated()->json('data.id'));
    $creditSale = $this->postJson('/api/v1/sales', [
        'customer_id' => $customer->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/sales/{$creditSale}/void", ['reason' => 'Entered twice'])->assertOk();

    $this->postJson('/api/v1/transfers', ['from_account_id' => $this->cash->id, 'to_account_id' => $this->bank->id, 'amount' => '300.00'])
        ->assertCreated();
    $this->postJson('/api/v1/transfers', ['from_account_id' => $this->cash->id, 'to_account_id' => $this->bank->id, 'amount' => '50.00']);
    $wrongTransfer = AccountTransaction::latest('id')->first();
    $this->postJson("/api/v1/account-transactions/{$wrongTransfer->id}/reverse", ['reason' => 'Wrong amount'])->assertCreated();
});

it('passes on real history', function () {
    $this->artisan('inventory:verify')
        ->expectsOutputToContain('Everything matches the ledgers.')
        ->assertSuccessful();
});

it('finds a document whose total does not match its items', function () {
    DB::table('sales')->where('id', $this->sale->id)->update(['total_amount' => '1500.00']);

    $this->artisan('inventory:verify')
        ->expectsOutputToContain("{$this->sale->number}: total 1500.00, items add up to 1440.00")
        ->assertFailed();
});

it('finds a missing stock movement and the negative stock it hides', function () {
    $purchase = Purchase::first();
    DB::table('stock_movements')->where('reference_type', 'purchase')->delete();

    $this->artisan('inventory:verify')
        ->expectsOutputToContain("{$purchase->number}, product {$this->product->id}: stock moved 0, expected 10")
        ->expectsOutputToContain('CEM-50: stock is -2')
        ->assertFailed();
});

it('finds stock moved for a product that is not on the document', function () {
    StockMovement::factory()->create(['reference_type' => 'sale', 'reference_id' => $this->sale->id, 'quantity' => -1]);

    $this->artisan('inventory:verify')
        ->expectsOutputToContain("sale {$this->sale->id}: stock movements for product")
        ->assertFailed();
});

it('finds payments above the total', function () {
    DB::table('account_transactions')->where('reference_type', 'sale')->update(['amount' => '1500.00']);

    $this->artisan('inventory:verify')
        ->expectsOutputToContain("{$this->sale->number}: payments 1500.00, total 1440.00")
        ->assertFailed();
});

it('finds a transfer that does not sum to zero', function () {
    DB::table('account_transactions')->where('type', 'transfer_in')->where('amount', '300.00')->update(['amount' => '299.00']);

    $this->artisan('inventory:verify')
        ->expectsOutputToContain('2 rows summing to -1.00')
        ->assertFailed();
});

it('finds a reversal that does not cancel its entry', function () {
    $reversal = AccountTransaction::where('type', 'reversal')->where('amount', '-50.00')->first();
    DB::table('account_transactions')->where('id', $reversal->id)->update(['amount' => '-40.00']);

    $this->artisan('inventory:verify')
        ->expectsOutputToContain("Reversal {$reversal->id} does not cancel transaction {$reversal->reverses_transaction_id}")
        ->assertFailed();
});

it('finds an inactive account that still holds money', function () {
    DB::table('accounts')->where('id', $this->bank->id)->update(['is_active' => false]);

    $this->artisan('inventory:verify')
        ->expectsOutputToContain('Bank is inactive with a balance of 300.00')
        ->assertFailed();
});
