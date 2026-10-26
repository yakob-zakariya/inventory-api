<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\DocumentVoider;
use App\Services\PurchaseRecorder;
use App\Services\SaleRecorder;

beforeEach(function () {
    actingAsOwner();
    $product = Product::factory()->create(['selling_price' => '10.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 5]);
    $this->sale = app(SaleRecorder::class)->record([
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'note' => 'Deliver Monday',
    ]);
    $this->purchase = app(PurchaseRecorder::class)->record([
        'supplier_id' => Supplier::factory()->create()->id,
        'invoice_number' => 'INV-1',
        'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => '5.00']],
    ]);
});

it('edits a sale note', function () {
    $this->patchJson("/api/v1/sales/{$this->sale->id}", ['note' => 'Deliver Tuesday'])
        ->assertOk()
        ->assertJsonPath('data.note', 'Deliver Tuesday')
        ->assertJsonPath('data.total_amount', '10.00');
});

it('edits a purchase note and invoice number', function () {
    $this->patchJson("/api/v1/purchases/{$this->purchase->id}", ['invoice_number' => 'INV-1A', 'note' => null])
        ->assertOk()
        ->assertJsonPath('data.invoice_number', 'INV-1A')
        ->assertJsonPath('data.note', null);
});

it('refuses to change anything financial', function () {
    $this->patchJson("/api/v1/sales/{$this->sale->id}", ['note' => 'x', 'total_amount' => '1.00', 'customer_id' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'total_amount' => 'The total_amount cannot be changed. Only note can be edited; void the document to correct anything else.',
            'customer_id',
        ]);

    $this->patchJson("/api/v1/purchases/{$this->purchase->id}", ['items' => []])
        ->assertJsonValidationErrors(['items' => 'The items cannot be changed. Only note and invoice_number can be edited; void the document to correct anything else.']);

    expect($this->sale->fresh()->note)->toBe('Deliver Monday');
});

it('edits the note of a voided document', function () {
    app(DocumentVoider::class)->void($this->sale, 'Cancelled');

    $this->patchJson("/api/v1/sales/{$this->sale->id}", ['note' => 'Customer changed their mind'])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided')
        ->assertJsonPath('data.note', 'Customer changed their mind');
});

it('needs the create permission of the document', function () {
    actingAsStaff();

    $this->patchJson("/api/v1/sales/{$this->sale->id}", ['note' => 'x'])->assertOk();
    $this->patchJson("/api/v1/purchases/{$this->purchase->id}", ['note' => 'x'])->assertForbidden();
});
