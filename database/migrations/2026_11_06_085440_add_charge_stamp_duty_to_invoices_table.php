<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the stamp duty is charged back to the customer ("Rimborso imposta di bollo" line).
     * The duty itself stays due and declared (DatiBollo) either way.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('charge_stamp_duty')->default(true)->after('stamp_duty_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('charge_stamp_duty');
        });
    }
};
