<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Quotes given to customers. They never touch stock or money; converting one creates a sale.
     */
    public function up(): void
    {
        Schema::create('proformas', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->date('proforma_date')->index();
            $table->date('valid_until');
            $table->decimal('total_amount', 12, 2);
            $table->text('note')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('sale_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proformas');
    }
};
