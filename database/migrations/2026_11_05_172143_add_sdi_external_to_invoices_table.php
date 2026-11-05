<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transmitted to the SDI outside Prugna (e.g. issued in Dolibarr): never sent from here.
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('sdi_external')->default(false)->after('sdi_status');
        });

        // Invoices issued in Dolibarr and imported with their number.
        DB::table('invoices')
            ->whereNotNull('number')
            ->whereExists(fn (Builder $query) => $query->select(DB::raw(1))
                ->from('dolibarr_links')
                ->where('dolibarr_links.source', 'facture')
                ->where('dolibarr_links.linkable_type', 'App\\Models\\Invoice')
                ->whereColumn('dolibarr_links.linkable_id', 'invoices.id')
                ->whereColumn('dolibarr_links.reference', 'invoices.number'))
            ->update(['sdi_external' => true]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('sdi_external');
        });
    }
};
