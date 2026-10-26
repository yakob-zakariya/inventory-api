<?php

use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\DocumentVoider;
use App\Services\PurchaseRecorder;
use App\Services\SaleRecorder;

/**
 * A sale of 100.00 to the named customer with the given amount paid.
 */
function saleTo(string $customer, string $paid): Sale
{
    $product = Product::factory()->create(['selling_price' => '100.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 1]);

    return app(SaleRecorder::class)->record([
        'customer_id' => Customer::factory()->create(['name' => $customer])->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => $paid === '0' ? [] : [['account_id' => Account::factory()->create()->id, 'amount' => $paid]],
    ]);
}

/**
 * A purchase of 100.00 from the named supplier with the given amount paid.
 */
function purchaseFrom(string $supplier, string $invoice, string $paid): Purchase
{
    return app(PurchaseRecorder::class)->record([
        'supplier_id' => Supplier::factory()->create(['name' => $supplier])->id,
        'invoice_number' => $invoice,
        'items' => [['product_id' => Product::factory()->create()->id, 'quantity' => 1, 'unit_cost' => '100.00']],
        'payments' => $paid === '0' ? [] : [['account_id' => Account::factory()->create(['opening_balance' => '500.00'])->id, 'amount' => $paid]],
    ]);
}

beforeEach(function () {
    actingAsOwner();
    $this->unpaid = saleTo('Abebe Kebede', '0');
    $this->partial = saleTo('Almaz Tesfaye', '40.00');
    $this->paid = saleTo('Kebede Alemu', '100.00');
    $this->voided = app(DocumentVoider::class)->void(saleTo('Voided Customer', '0'), 'Cancelled');
});

it('filters sales by payment status', function (string $status, string $sale) {
    $this->getJson("/api/v1/sales?payment_status={$status}")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->{$sale}->id)
        ->assertJsonPath('data.0.payment_status', $status);
})->with([['unpaid', 'unpaid'], ['partial', 'partial'], ['paid', 'paid']]);

it('searches sales by number or customer name', function () {
    $this->getJson('/api/v1/sales?search=kebede')->assertJsonPath('data.*.id', [$this->paid->id, $this->unpaid->id]);
    $this->getJson("/api/v1/sales?search={$this->partial->number}")->assertJsonPath('data.*.id', [$this->partial->id]);
});

it('combines search and payment status', function () {
    $this->getJson('/api/v1/sales?search=kebede&payment_status=unpaid')->assertJsonPath('data.*.id', [$this->unpaid->id]);
});

it('filters and searches purchases', function () {
    $owed = purchaseFrom('Dangote Cement', 'INV-777', '0');
    $partly = purchaseFrom('Derba Cement', 'INV-888', '30.00');
    purchaseFrom('Nail Factory', 'INV-999', '100.00');

    $this->getJson('/api/v1/purchases?search=cement')->assertJsonPath('data.*.id', [$partly->id, $owed->id]);
    $this->getJson('/api/v1/purchases?search=INV-777')->assertJsonPath('data.*.id', [$owed->id]);
    $this->getJson("/api/v1/purchases?search={$partly->number}")->assertJsonPath('data.*.id', [$partly->id]);
    $this->getJson('/api/v1/purchases?payment_status=partial')->assertJsonPath('data.*.id', [$partly->id]);
    $this->getJson('/api/v1/purchases?payment_status=paid')->assertJsonPath('data.*.supplier.name', ['Nail Factory']);
});

it('rejects an unknown payment status', function () {
    $this->getJson('/api/v1/sales?payment_status=overdue')->assertJsonValidationErrors('payment_status');
});
