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
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('type', 16)->default('item');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained()->nullOnDelete();
            // Lines added by the system: "stamp_duty" (bollo charged to the customer) or "deposit_deduction".
            $table->string('system', 24)->nullable();
            $table->foreignId('deducts_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
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
            $table->timestamps();

            $table->index(['invoice_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
