<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Interventions invoiced outside Prugna (e.g. in Dolibarr before switching): marked by hand,
     * they count as invoiced and are never proposed again.
     */
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->timestamp('invoiced_outside_at')->nullable()->after('billable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn('invoiced_outside_at');
        });
    }
};
