<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hour packages sold with a quote: when the quote is accepted each one becomes an hour contract
     * (monte ore) of the customer, used up by the interventions.
     */
    public function up(): void
    {
        Schema::table('quote_lines', function (Blueprint $table) {
            // Null: an ordinary line.
            $table->decimal('package_hours', 8, 2)->nullable()->after('is_selected');
            // Validity of the package in months (null: no expiry).
            $table->unsignedSmallInteger('package_months')->nullable()->after('package_hours');
        });

        Schema::table('service_contracts', function (Blueprint $table) {
            $table->foreignId('quote_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
            $table->foreignId('quote_line_id')->nullable()->unique()->after('quote_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quote_line_id');
            $table->dropConstrainedForeignId('quote_id');
        });

        Schema::table('quote_lines', function (Blueprint $table) {
            $table->dropColumn(['package_hours', 'package_months']);
        });
    }
};
