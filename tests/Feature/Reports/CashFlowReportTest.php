<?php

use App\Enums\AccountTransactionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\MoneyLedger;
use App\Services\SaleRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Cash opens at 1000 and bank at 500, plus a 200 deposit in September.
 * In 2026-10-01..2026-10-20: a 300 sale payment and a 100 one reversed (cash), a 50 expense (cash),
 * a 400 transfer cash to bank, a +15 adjustment (bank). After the period: a 10 expense.
 */
beforeEach(function () {
    $this->travelTo('2026-10-26 12:00:00');
    actingAsOwner();

    $this->cash = Account::factory()->create(['name' => 'Cash', 'opening_balance' => '1000.00']);
    $this->bank = Account::factory()->create(['name' => 'Bank', 'opening_balance' => '500.00']);
    $product = Product::factory()->create(['selling_price' => '100.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 10]);
    $sell = fn (string $date, int $quantity, string $paid) => app(SaleRecorder::class)->record([
        'customer_id' => Customer::factory()->create()->id,
        'sale_date' => $date,
        'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        'payments' => [['account_id' => $this->cash->id, 'amount' => $paid]],
    ]);

    $sell('2026-10-02', 3, '300.00');
    $wrongPayment = $sell('2026-10-03', 1, '100.00')->payments()->sole();

    DB::transaction(function () use ($wrongPayment) {
        $ledger = app(MoneyLedger::class);
        $ledger->record($this->cash, AccountTransactionType::OwnerDeposit, 20000, '2026-09-20');
        $ledger->reverse($wrongPayment, 'Wrong customer', '2026-10-04');
        $ledger->record($this->cash, AccountTransactionType::Expense, -5000, '2026-10-05', 'Rent');
        $ledger->recordTransfer($this->cash, $this->bank, 40000, '2026-10-06');
        $ledger->record($this->bank, AccountTransactionType::Adjustment, 1500, '2026-10-07', 'Bank interest');
        $ledger->record($this->cash, AccountTransactionType::Expense, -1000, '2026-10-25', 'Later');
    });
});

it('reports cash flow across all accounts without transfers', function () {
    $this->getJson('/api/v1/reports/cash-flow?from=2026-10-01&to=2026-10-20')
        ->assertOk()
        ->assertExactJson(['data' => [
            'from' => '2026-10-01', 'to' => '2026-10-20', 'account' => null,
            'opening_balance' => '1700.00', 'money_in' => '315.00', 'money_out' => '50.00', 'closing_balance' => '1965.00',
            'by_type' => [
                ['type' => 'sale_payment', 'in' => '300.00', 'out' => '0.00'],
                ['type' => 'expense', 'in' => '0.00', 'out' => '50.00'],
                ['type' => 'adjustment', 'in' => '15.00', 'out' => '0.00'],
            ],
        ]]);
});

it('includes transfers for one account', function () {
    $this->getJson("/api/v1/reports/cash-flow?from=2026-10-01&to=2026-10-20&account_id={$this->cash->id}")
        ->assertJsonPath('data.account', ['id' => $this->cash->id, 'name' => 'Cash'])
        ->assertJsonPath('data.opening_balance', '1200.00')
        ->assertJsonPath('data.money_in', '300.00')
        ->assertJsonPath('data.money_out', '450.00')
        ->assertJsonPath('data.closing_balance', '1050.00')
        ->assertJsonPath('data.by_type.*.type', ['sale_payment', 'expense', 'transfer_out']);

    $this->getJson("/api/v1/reports/cash-flow?from=2026-10-01&to=2026-10-20&account_id={$this->bank->id}")
        ->assertJsonPath('data.opening_balance', '500.00')
        ->assertJsonPath('data.money_in', '415.00')
        ->assertJsonPath('data.closing_balance', '915.00');
});

it('always closes at opening plus money in minus money out', function (?string $account) {
    $accountId = $account ? $this->{$account}->id : null;

    foreach ([['2026-09-01', '2026-10-31'], ['2026-10-03', '2026-10-04'], ['2026-10-06', '2026-10-26']] as [$from, $to]) {
        $data = $this->getJson("/api/v1/reports/cash-flow?from={$from}&to={$to}&account_id={$accountId}")->json('data');

        expect(bcadd(bcsub($data['opening_balance'], $data['money_out'], 2), $data['money_in'], 2))->toBe($data['closing_balance']);
    }
})->with(['all accounts' => null, 'cash' => 'cash', 'bank' => 'bank']);

it('nets a reversed payment into the type it cancels', function () {
    $this->getJson('/api/v1/reports/cash-flow?from=2026-10-03&to=2026-10-04')
        ->assertJsonPath('data.by_type', [['type' => 'sale_payment', 'in' => '0.00', 'out' => '0.00']])
        ->assertJsonPath('data.money_in', '0.00');
});

it('groups by day with every day present', function () {
    $this->getJson('/api/v1/reports/cash-flow?from=2026-10-01&to=2026-10-03&group_by=day')
        ->assertJsonPath('data.series', [
            ['period' => '2026-10-01', 'in' => '0.00', 'out' => '0.00'],
            ['period' => '2026-10-02', 'in' => '300.00', 'out' => '0.00'],
            ['period' => '2026-10-03', 'in' => '100.00', 'out' => '0.00'],
        ]);
});

it('rejects an unknown account', function () {
    $this->getJson('/api/v1/reports/cash-flow?account_id=999')->assertJsonValidationErrors('account_id');
});

it('is for users with reports.view only', function () {
    actingAsStaff();

    $this->getJson('/api/v1/reports/cash-flow')->assertForbidden();
});
