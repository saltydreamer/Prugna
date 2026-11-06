<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hour packages sold with an order: invoiced with the order, never on their own.
     */
    public function up(): void
    {
        Schema::table('service_contracts', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('quote_line_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
