<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prices agreed with a single customer: either a fixed price or a discount on the list price.
     */
    public function up(): void
    {
        Schema::create('customer_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 4)->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->date('valid_until')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_prices');
    }
};
