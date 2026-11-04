<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Categories of interventions, each billed with one service for remote work and one for on-site work.
     */
    public function up(): void
    {
        Schema::create('intervention_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('remote_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('onsite_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intervention_categories');
    }
};
