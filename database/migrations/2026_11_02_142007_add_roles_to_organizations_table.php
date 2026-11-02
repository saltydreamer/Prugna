<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An organization can be a customer, a supplier or both.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_customer')->default(true)->after('type');
            $table->boolean('is_supplier')->default(false)->after('is_customer')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['is_supplier']);
            $table->dropColumn(['is_customer', 'is_supplier']);
        });
    }
};
