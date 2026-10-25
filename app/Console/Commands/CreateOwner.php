<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('inventory:create-owner {--name= : The owner\'s name} {--email= : The owner\'s email address}')]
#[Description('Create a user with the owner role (seeds roles and permissions if needed)')]
class CreateOwner extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?? text('Name', required: true),
            'email' => $this->option('email') ?? text('Email', required: true),
            'password' => password('Password', required: true),
            'password_confirmation' => password('Confirm password', required: true),
        ];

        $validator = Validator::make($data, StoreUserRequest::accountRules());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($validator) {
            app(RolesAndPermissionsSeeder::class)->run();

            $user = User::create($validator->safe()->only(['name', 'email', 'password']));
            $user->assignRole(Role::Owner->value);

            return $user;
        });

        $this->components->info("Owner {$user->email} created.");

        return self::SUCCESS;
    }
}
