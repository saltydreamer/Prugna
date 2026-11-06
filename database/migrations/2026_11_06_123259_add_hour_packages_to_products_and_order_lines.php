<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hour packages sold as an article: products flagged "monte ore prepagato", order lines carrying
     * the package (like quote lines) and the contract made from an order line.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('hour_package')->default(false)->after('billing_period');
            $table->unsignedSmallInteger('package_months')->nullable()->after('hour_package');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->decimal('package_hours', 8, 2)->nullable()->after('quantity_invoiced');
            $table->unsignedSmallInteger('package_months')->nullable()->after('package_hours');
        });

        // Order lines are replaced on every save: the sync points the contract to the current line.
        Schema::table('service_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('order_line_id')->nullable()->after('order_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('service_contracts', function (Blueprint $table) {
            $table->dropIndex(['order_line_id']);
            $table->dropColumn('order_line_id');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn(['package_hours', 'package_months']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['hour_package', 'package_months']);
        });
    }
};
