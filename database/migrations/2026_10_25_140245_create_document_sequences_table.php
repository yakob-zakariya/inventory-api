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
     * One row per numbered document type, created here so every environment has them.
     */
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32)->unique();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        DB::table('document_sequences')->insert([
            ['type' => 'purchase', 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'sale', 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
