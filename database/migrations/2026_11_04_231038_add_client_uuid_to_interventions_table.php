<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Interventions recorded offline carry the id generated on the device: syncing twice never duplicates them.
     */
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->unique()->after('number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
