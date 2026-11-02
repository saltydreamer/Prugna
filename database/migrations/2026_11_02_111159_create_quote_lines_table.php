<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            // item | section (heading) | text (free text)
            $table->string('type', 16)->default('item');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 64)->nullable();
            $table->text('description');
            $table->string('unit', 16)->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 4)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->foreignId('vat_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->string('vat_nature', 8)->nullable();
            // Optional lines count in the totals only when selected (by us or by the customer).
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_selected')->default(true);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['quote_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
    }
};
