<?php

use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\BusinessSummary;
use App\Services\PurchaseRecorder;
use App\Services\SaleRecorder;

/**
 * A recorded purchase of the given products: [product, quantity, unit cost].
 *
 * @param  list<array{Product, int, string}>  $lines
 */
function purchaseOf(array $lines, array $payments = []): Purchase
{
    return app(PurchaseRecorder::class)->record([
        'supplier_id' => Supplier::factory()->create()->id,
        'items' => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1], 'unit_cost' => $line[2]], $lines),
        'payments' => $payments,
    ]);
}

/**
 * A recorded credit sale of the given quantity of a product.
 */
function saleOf(Product $product, int $quantity, array $payments = []): Sale
{
    return app(SaleRecorder::class)->record([
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        'payments' => $payments,
    ]);
}

it('voids a sale and puts its stock back', function () {
    $owner = actingAsOwner();
    $product = Product::factory()->create(['selling_price' => '100.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 10]);
    $sale = saleOf($product, 4);

    $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'Customer cancelled'])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided')
        ->assertJsonPath('data.void_reason', 'Customer cancelled')
        ->assertJsonPath('data.voided_by', $owner->id)
        ->assertJsonPath('data.number', $sale->number);

    $reversal = $sale->stockMovements()->where('type', 'reversal')->sole();

    expect($product->currentStock())->toBe(10)
        ->and($reversal->quantity)->toBe(4)
        ->and($reversal->note)->toBe('Customer cancelled')
        ->and($sale->fresh()->voided_at)->not->toBeNull();
});

it('voids a purchase and takes its stock out again', function () {
    actingAsOwner();
    $product = Product::factory()->create();
    $purchase = purchaseOf([[$product, 5, '20.00']]);

    $this->postJson("/api/v1/purchases/{$purchase->id}/void", ['reason' => 'Entered twice'])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided');

    expect($product->currentStock())->toBe(0)
        ->and($purchase->stockMovements()->where('type', 'reversal')->sole()->quantity)->toBe(-5);
});

it('refuses to void a purchase whose stock was already sold', function () {
    actingAsOwner();
    $product = Product::factory()->create();
    $purchase = purchaseOf([[$product, 5, '20.00']]);
    saleOf($product, 3);

    $this->postJson("/api/v1/purchases/{$purchase->id}/void", ['reason' => 'Wrong supplier'])
        ->assertUnprocessable()
        ->assertJsonPath('message', "Not enough stock for {$product->sku}: 2 on hand, 5 requested.");

    expect($purchase->fresh()->status->value)->toBe('active')
        ->and($product->currentStock())->toBe(2);
});

it('refuses to void a document with payments until they are reversed', function () {
    actingAsOwner();
    $cash = Account::factory()->create();
    $product = Product::factory()->create(['selling_price' => '100.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 10]);
    $sale = saleOf($product, 2, [['account_id' => $cash->id, 'amount' => '50.00']]);

    $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'Mistake'])
        ->assertUnprocessable()
        ->assertJsonPath('message', "{$sale->number} has payments of 50.00. Reverse them before voiding it.");

    $payment = $sale->payments()->sole();
    $this->postJson("/api/v1/account-transactions/{$payment->id}/reverse", ['reason' => 'Mistake'])->assertCreated();

    $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'Mistake'])->assertOk();
})->todo('needs the reverse endpoint (next step)');

it('refuses to void a document twice', function () {
    actingAsOwner();
    $purchase = purchaseOf([[Product::factory()->create(), 1, '10.00']]);

    $this->postJson("/api/v1/purchases/{$purchase->id}/void", ['reason' => 'Duplicate'])->assertOk();

    $this->postJson("/api/v1/purchases/{$purchase->id}/void", ['reason' => 'Duplicate'])
        ->assertConflict()
        ->assertJsonPath('message', "{$purchase->number} is already voided.");

    expect($purchase->stockMovements()->where('type', 'reversal')->count())->toBe(1);
});

it('requires a reason', function () {
    actingAsOwner();
    $purchase = purchaseOf([[Product::factory()->create(), 1, '10.00']]);

    $this->postJson("/api/v1/purchases/{$purchase->id}/void", ['reason' => ''])
        ->assertJsonValidationErrors(['reason' => 'A reason is required to void a document.']);
});

it('refuses a void when a product on it was deleted since', function () {
    actingAsOwner();
    $product = Product::factory()->create(['selling_price' => '10.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 1]);
    $sale = saleOf($product, 1);
    $product->delete();

    $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'Mistake'])
        ->assertUnprocessable()
        ->assertJsonPath('message', "Product {$product->id} is no longer available.");
});

it('only lets users with the void permissions void', function () {
    actingAsOwner();
    $product = Product::factory()->create(['selling_price' => '10.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 1]);
    $sale = saleOf($product, 1);
    $purchase = purchaseOf([[Product::factory()->create(), 1, '10.00']]);

    actingAsStaff();

    $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/purchases/{$purchase->id}/void", ['reason' => 'x'])->assertForbidden();
});

it('returns 404 for an unknown document', function () {
    actingAsOwner();

    $this->postJson('/api/v1/sales/999/void', ['reason' => 'x'])->assertNotFound();
});

it('leaves voided documents out of receivables, payables and inventory', function () {
    actingAsOwner();
    $product = Product::factory()->create(['selling_price' => '100.00']);
    $purchase = purchaseOf([[$product, 5, '20.00']]);
    $sale = saleOf($product, 2);

    $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'Cancelled'])->assertOk();
    $this->postJson("/api/v1/purchases/{$purchase->id}/void", ['reason' => 'Cancelled'])->assertOk();

    $summary = BusinessSummary::now();

    expect($summary->receivablesCents)->toBe(0)
        ->and($summary->payablesCents)->toBe(0)
        ->and($summary->inventoryAtCostCents)->toBe(0);
});

describe('restoring the product cost', function () {
    it('puts back the cost the product had before the purchase', function () {
        actingAsOwner();
        $product = Product::factory()->create(['cost_price' => '10.00']);
        $purchase = purchaseOf([[$product, 1, '1000.00']]);

        $this->postJson("/api/v1/purchases/{$purchase->id}/void", ['reason' => 'Typo in cost'])->assertOk();

        expect($product->fresh()->cost_price)->toBe('10.00');
    });

    it('leaves the cost alone when a later purchase set it', function () {
        actingAsOwner();
        $product = Product::factory()->create(['cost_price' => '10.00']);
        $first = purchaseOf([[$product, 1, '12.00']]);
        purchaseOf([[$product, 1, '15.00']]);

        $this->postJson("/api/v1/purchases/{$first->id}/void", ['reason' => 'Wrong'])->assertOk();

        expect($product->fresh()->cost_price)->toBe('15.00');
    });

    it('leaves the cost alone when it was edited by hand', function () {
        actingAsOwner();
        $product = Product::factory()->create(['cost_price' => '10.00']);
        $purchase = purchaseOf([[$product, 1, '12.00']]);
        $product->update(['cost_price' => '11.00']);

        $this->postJson("/api/v1/purchases/{$purchase->id}/void", ['reason' => 'Wrong'])->assertOk();

        expect($product->fresh()->cost_price)->toBe('11.00');
    });

    it('walks back through purchases voided newest first', function () {
        actingAsOwner();
        $product = Product::factory()->create(['cost_price' => '10.00']);
        $first = purchaseOf([[$product, 1, '12.00']]);
        $second = purchaseOf([[$product, 1, '15.00']]);

        $this->postJson("/api/v1/purchases/{$second->id}/void", ['reason' => 'Wrong'])->assertOk();
        expect($product->fresh()->cost_price)->toBe('12.00');

        $this->postJson("/api/v1/purchases/{$first->id}/void", ['reason' => 'Wrong'])->assertOk();
        expect($product->fresh()->cost_price)->toBe('10.00');
    });
});
