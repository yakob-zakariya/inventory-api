<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Proformas are numbered PRF-000001 from their own sequence row.
     */
    public function up(): void
    {
        DB::table('document_sequences')->insert(['type' => 'proforma', 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('document_sequences')->where('type', 'proforma')->delete();
    }
};
