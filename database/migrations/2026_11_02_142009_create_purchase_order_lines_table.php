<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lines of a supplier order at cost. "rebill" lines are charged back to the customer
     * with a markup; the invoice line that rebills them points back here.
     */
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
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
            $table->decimal('total', 12, 2)->default(0);
            $table->boolean('rebill')->default(false);
            $table->decimal('markup_percent', 6, 2)->default(0);
            $table->timestamps();

            $table->index(['purchase_order_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
