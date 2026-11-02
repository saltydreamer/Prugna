<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers and suppliers are separate registries: an organization has exactly one role.
     * Organizations used only as suppliers (supplier orders, no sales documents) become suppliers.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('role', 16)->default('customer')->after('type')->index();
        });

        $hasSales = fn (string $table) => DB::table($table)->select('organization_id');

        DB::table('organizations')
            ->where('is_supplier', true)
            ->where(fn ($query) => $query
                ->where('is_customer', false)
                ->orWhere(fn ($query) => $query
                    ->whereIn('id', DB::table('purchase_orders')->select('supplier_id'))
                    ->whereNotIn('id', $hasSales('quotes'))
                    ->whereNotIn('id', $hasSales('orders'))
                    ->whereNotIn('id', $hasSales('invoices'))))
            ->update(['role' => 'supplier']);

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['is_supplier']);
            $table->dropColumn(['is_customer', 'is_supplier']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_customer')->default(true)->after('type');
            $table->boolean('is_supplier')->default(false)->after('is_customer')->index();
        });

        DB::table('organizations')->where('role', 'supplier')->update(['is_customer' => false, 'is_supplier' => true]);

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
