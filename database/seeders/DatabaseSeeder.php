<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
     * A few categories, products, a supplier, a customer and accounts to try the API with.
     */
    private function seedDemoMasterData(): void
    {
        if (Category::exists()) {
            return;
        }

        $building = Category::factory()->create(['name' => 'Building materials']);
        $paint = Category::factory()->create(['name' => 'Paint']);

        Product::factory()->for($building)->create([
            'sku' => 'CEM-50', 'name' => 'Cement 50kg', 'unit' => 'bag',
            'cost_price' => '650.00', 'selling_price' => '720.00', 'reorder_level' => 20,
        ]);
        Product::factory()->for($building)->create([
            'sku' => 'NAIL-3', 'name' => 'Nails 3 inch', 'unit' => 'kg',
            'cost_price' => '120.00', 'selling_price' => '150.00', 'reorder_level' => 10,
        ]);
        Product::factory()->for($paint)->create([
            'sku' => 'PNT-W4', 'name' => 'White paint 4L', 'unit' => 'tin',
            'cost_price' => '900.00', 'selling_price' => '1100.00', 'reorder_level' => 5,
        ]);

        Supplier::factory()->create(['name' => 'Demo Cement Supplier']);
        Customer::factory()->create(['name' => 'Demo Credit Customer']);

        Account::factory()->create(['name' => 'Cash drawer', 'type' => AccountType::Cash, 'opening_balance' => '5000.00']);
        Account::factory()->create(['name' => 'Bank', 'type' => AccountType::Bank, 'opening_balance' => '20000.00']);
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
