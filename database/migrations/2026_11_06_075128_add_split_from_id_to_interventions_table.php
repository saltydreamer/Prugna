<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Interventions obtained by splitting another one (e.g. a Dolibarr intervention that collected
     * several days of work): the original keeps the first part.
     */
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->foreignId('split_from_id')->nullable()->after('client_uuid')->constrained('interventions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('split_from_id');
        });
    }
};
