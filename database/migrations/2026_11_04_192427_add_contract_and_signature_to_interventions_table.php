<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hours used from a contract, customer signature and report sending.
     */
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('order_id')->constrained('service_contracts')->nullOnDelete();
            $table->unsignedInteger('contract_minutes')->default(0)->after('contract_id');
            $table->boolean('skip_contract')->default(false)->after('contract_minutes');
            // Fully covered by the contract, nothing left to invoice.
            $table->boolean('covered')->default(false)->after('skip_contract');
            $table->string('signature_path')->nullable();
            $table->string('signed_by_name')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('report_sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
            $table->dropColumn(['contract_minutes', 'skip_contract', 'covered', 'signature_path', 'signed_by_name', 'signed_at', 'report_sent_at']);
        });
    }
};
