<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The shop's logo for printed documents, a path on the public disk.
     */
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('tin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
