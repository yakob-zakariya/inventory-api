<?php

namespace Database\Seeders;

use App\Enums\Role;
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
        }
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
