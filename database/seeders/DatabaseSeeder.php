<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseRecorder;
use App\Services\SaleRecorder;
use App\Services\StockLedger;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->isLocal()) {
            $this->seedDemoUser('Demo Owner', 'owner@example.com', Role::Owner);
            $this->seedDemoUser('Demo Staff', 'staff@example.com', Role::Staff);
            $this->seedDemoMasterData();
        }
    }

    /**
     * A few categories, products with opening stock, a supplier, a customer, accounts, a purchase and two sales to try the API with.
     */
    private function seedDemoMasterData(): void
    {
        if (Category::exists()) {
            return;
        }

        $building = Category::factory()->create(['name' => 'Building materials']);
        $paint = Category::factory()->create(['name' => 'Paint']);

        $products = collect();

        $products[] = Product::factory()->for($building)->create([
            'sku' => 'CEM-50', 'name' => 'Cement 50kg', 'unit' => 'bag',
            'cost_price' => '650.00', 'selling_price' => '720.00', 'reorder_level' => 20,
        ]);
        $products[] = Product::factory()->for($building)->create([
            'sku' => 'NAIL-3', 'name' => 'Nails 3 inch', 'unit' => 'kg',
            'cost_price' => '120.00', 'selling_price' => '150.00', 'reorder_level' => 10,
        ]);
        $products[] = Product::factory()->for($paint)->create([
            'sku' => 'PNT-W4', 'name' => 'White paint 4L', 'unit' => 'tin',
            'cost_price' => '900.00', 'selling_price' => '1100.00', 'reorder_level' => 5,
        ]);

        DB::transaction(function () use ($products) {
            $ledger = app(StockLedger::class);
            $ledger->lockProducts($products->pluck('id')->all());

            foreach ($products as $product) {
                $ledger->record($product, StockMovementType::Adjustment, 50, 'Opening stock');
            }
        });

        $supplier = Supplier::factory()->create(['name' => 'Demo Cement Supplier']);
        $customer = Customer::factory()->create(['name' => 'Demo Credit Customer']);

        $cash = Account::factory()->create(['name' => 'Cash drawer', 'type' => AccountType::Cash, 'opening_balance' => '5000.00']);
        $bank = Account::factory()->create(['name' => 'Bank', 'type' => AccountType::Bank, 'opening_balance' => '20000.00']);

        [$cement, $nails, $paint] = $products->all();

        // A purchase on partial credit from the supplier.
        app(PurchaseRecorder::class)->record([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-1001',
            'items' => [['product_id' => $cement->id, 'quantity' => 20, 'unit_cost' => '640.00']],
            'payments' => [['account_id' => $bank->id, 'amount' => '5000.00']],
        ]);

        // A walk-in sale, paid in full.
        app(SaleRecorder::class)->record([
            'items' => [
                ['product_id' => $cement->id, 'quantity' => 2],
                ['product_id' => $paint->id, 'quantity' => 1],
            ],
            'payments' => [['account_id' => $cash->id, 'amount' => '2540.00']],
        ]);

        // A credit sale to a registered customer.
        app(SaleRecorder::class)->record([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $nails->id, 'quantity' => 5, 'unit_price' => '145.00']],
            'payments' => [['account_id' => $cash->id, 'amount' => '200.00']],
        ]);
    }

    /**
     * Create a demo login (password "password") unless it already exists.
     */
    private function seedDemoUser(string $name, string $email, Role $role): void
    {
        if (User::where('email', $email)->exists()) {
            return;
        }

        User::factory()->create(['name' => $name, 'email' => $email])->assignRole($role->value);
    }
}
