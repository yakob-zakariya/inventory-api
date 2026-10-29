<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row holding the shop's details, started from the SHOP_* environment values
     * and edited from the app afterwards.
     */
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address', 2000)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('tin', 32)->nullable();
            $table->timestamps();
        });

        DB::table('shop_settings')->insert([
            'name' => config('shop.name'),
            'address' => config('shop.address'),
            'phone' => config('shop.phone'),
            'email' => config('shop.email'),
            'tin' => config('shop.tin'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};
