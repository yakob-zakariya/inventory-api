<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An entry is reversed at most once, enforced by the database as well as the code.
     */
    public function up(): void
    {
        Schema::table('account_transactions', function (Blueprint $table) {
            $table->unique('reverses_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * MySQL lets the unique index serve the foreign key, so a plain index must exist before it is dropped.
     */
    public function down(): void
    {
        Schema::table('account_transactions', function (Blueprint $table) {
            $table->index('reverses_transaction_id');
            $table->dropUnique(['reverses_transaction_id']);
        });
    }
};
