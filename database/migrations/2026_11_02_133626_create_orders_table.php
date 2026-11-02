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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('number', 32)->unique();
            $table->string('status', 16)->default('open')->index();
            $table->foreignId('quote_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('customer');
            $table->string('title')->nullable();
            $table->date('order_date');
            $table->date('delivery_date')->nullable()->index();
            // Customer's own order number and date (reported in the invoice: DatiOrdineAcquisto).
            $table->string('customer_reference', 64)->nullable();
            $table->date('customer_reference_date')->nullable();
            $table->foreignId('payment_term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['year', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
