<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Costs: supplier invoices, receipts and supplier credit notes, with due date and payment.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->default('invoice')->index();
            $table->string('category', 24)->nullable()->index();
            $table->foreignId('supplier_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->json('supplier');
            $table->string('number', 64)->nullable();
            $table->date('document_date')->index();
            $table->string('title')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_method', 16)->nullable();
            $table->date('due_date')->nullable()->index();
            $table->date('paid_at')->nullable()->index();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->string('source', 16)->default('manual');
            $table->foreignId('ai_extraction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
