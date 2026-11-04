<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Interventions billed by an invoice line (hours, call-out, km, travel, materials).
     */
    public function up(): void
    {
        Schema::create('invoice_line_interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intervention_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['invoice_line_id', 'intervention_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_line_interventions');
    }
};
