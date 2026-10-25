<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only money ledger: rows are never updated or deleted.
     */
    public function up(): void
    {
        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->decimal('amount', 12, 2);
            $table->date('transaction_date');
            $table->text('note')->nullable();
            $table->string('receipt_reference')->nullable();
            $table->nullableMorphs('reference');
            $table->uuid('transfer_group')->nullable()->index();
            $table->foreignId('reverses_transaction_id')->nullable()->constrained('account_transactions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_id', 'transaction_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_transactions');
    }
};
