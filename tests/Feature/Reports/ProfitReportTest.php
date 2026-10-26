<?php

use App\Enums\AccountTransactionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\DocumentVoider;
use App\Services\MoneyLedger;
use App\Services\SaleRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Sales and expenses around October 2026 (today in these tests is later in the month).
 * In 2026-10-01..2026-10-20: sales 290 at cost 180, net expenses 30 (50 + 30 - 30 reversed - 20 reversed).
 */
beforeEach(function () {
    $this->travelTo('2026-10-26 12:00:00');
    actingAsOwner();

    $product = Product::factory()->create(['cost_price' => '60.00', 'selling_price' => '100.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 20]);
    $customer = Customer::factory()->create();
    $sell = fn (string $date, int $quantity, ?string $price = null) => app(SaleRecorder::class)->record([
        'customer_id' => $customer->id,
        'sale_date' => $date,
        'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price]],
    ]);

    $sell('2026-09-30', 1);
    $sell('2026-10-01', 2);
    $sell('2026-10-20', 1, '90.00');
    app(DocumentVoider::class)->void($sell('2026-10-10', 5), 'Cancelled');

    $cash = Account::factory()->create(['opening_balance' => '1000.00']);
    DB::transaction(function () use ($cash) {
        $ledger = app(MoneyLedger::class);
        $september = $ledger->record($cash, AccountTransactionType::Expense, -2000, '2026-09-15', 'Old expense');
        $ledger->reverse($september, 'Entered twice', '2026-10-02');
        $ledger->record($cash, AccountTransactionType::Expense, -5000, '2026-10-05', 'Rent');
        $wrong = $ledger->record($cash, AccountTransactionType::Expense, -3000, '2026-10-06', 'Wrong amount');
        $ledger->reverse($wrong, 'Wrong amount', '2026-10-07');
        $ledger->record($cash, AccountTransactionType::OwnerDeposit, 99900, '2026-10-08');
    });
});

it('reports profit for a period', function () {
    $this->getJson('/api/v1/reports/profit?from=2026-10-01&to=2026-10-20')
        ->assertOk()
        ->assertExactJson(['data' => [
            'from' => '2026-10-01', 'to' => '2026-10-20',
            'sales' => '290.00', 'cost_of_sales' => '180.00', 'gross_profit' => '110.00',
            'expenses' => '30.00', 'net_profit' => '80.00',
        ]]);
});

it('includes sales on the first and last day of the period', function () {
    $this->getJson('/api/v1/reports/profit?from=2026-10-20&to=2026-10-20')->assertJsonPath('data.sales', '90.00');
    $this->getJson('/api/v1/reports/profit?from=2026-09-30&to=2026-09-30')->assertJsonPath('data.sales', '100.00');
});

it('groups by month', function () {
    $this->getJson('/api/v1/reports/profit?from=2026-09-01&to=2026-10-20&group_by=month')
        ->assertJsonPath('data.net_profit', '100.00')
        ->assertJsonPath('data.series', [
            ['period' => '2026-09', 'sales' => '100.00', 'cost_of_sales' => '60.00', 'gross_profit' => '40.00', 'expenses' => '20.00', 'net_profit' => '20.00'],
            ['period' => '2026-10', 'sales' => '290.00', 'cost_of_sales' => '180.00', 'gross_profit' => '110.00', 'expenses' => '30.00', 'net_profit' => '80.00'],
        ]);
});

it('groups by day with every day present', function () {
    $this->getJson('/api/v1/reports/profit?from=2026-10-01&to=2026-10-03&group_by=day')
        ->assertJsonPath('data.series.*.period', ['2026-10-01', '2026-10-02', '2026-10-03'])
        ->assertJsonPath('data.series.0.sales', '200.00')
        ->assertJsonPath('data.series.1.expenses', '-20.00')
        ->assertJsonPath('data.series.2.net_profit', '0.00');
});

it('covers the current month by default', function () {
    $this->getJson('/api/v1/reports/profit')
        ->assertJsonPath('data.from', '2026-10-01')
        ->assertJsonPath('data.to', '2026-10-31')
        ->assertJsonPath('data.sales', '290.00');
});

it('validates the period', function (string $query, string $field) {
    $this->getJson("/api/v1/reports/profit?{$query}")->assertJsonValidationErrors($field);
})->with([
    'to before from' => ['from=2026-10-10&to=2026-10-01', 'to'],
    'bad date' => ['from=10/01/2026', 'from'],
    'unknown grouping' => ['group_by=week', 'group_by'],
    'too many days' => ['from=2025-01-01&to=2026-10-01&group_by=day', 'group_by'],
]);

it('is for users with reports.view only', function () {
    actingAsStaff();

    $this->getJson('/api/v1/reports/profit')->assertForbidden();
});
