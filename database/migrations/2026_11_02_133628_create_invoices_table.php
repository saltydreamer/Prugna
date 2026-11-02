<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoices, deposit invoices and credit notes. The number is assigned on issue;
     * issued documents are never changed (corrections go through credit notes).
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            // invoice (TD01) | deposit (TD02) | credit_note (TD04)
            $table->string('type', 16)->default('invoice')->index();
            $table->string('status', 16)->default('draft')->index();
            // FT (invoices and deposits) or NC (credit notes), progressive per year.
            $table->string('series', 4);
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->string('number', 32)->nullable()->unique();
            $table->date('issue_date');
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('customer');
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('related_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('customer_reference', 64)->nullable();
            $table->date('customer_reference_date')->nullable();
            $table->string('cig', 15)->nullable();
            $table->string('cup', 15)->nullable();
            $table->foreignId('payment_term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_terms')->nullable();
            $table->string('payment_method', 8)->nullable();
            $table->date('due_date')->nullable()->index();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            // Fiscal data frozen on the document.
            $table->string('regime', 8);
            $table->boolean('split_payment')->default(false);
            $table->boolean('apply_withholding')->default(false);
            $table->string('withholding_type', 8)->nullable();
            $table->decimal('withholding_rate', 5, 2)->default(0);
            $table->string('withholding_reason', 4)->nullable();
            $table->decimal('withholding_amount', 12, 2)->default(0);
            $table->boolean('apply_pension')->default(false);
            $table->string('pension_type', 8)->nullable();
            $table->decimal('pension_rate', 5, 2)->default(0);
            $table->decimal('pension_vat_rate', 5, 2)->default(0);
            $table->string('pension_vat_nature', 8)->nullable();
            $table->boolean('pension_withholding')->default(false);
            $table->decimal('pension_amount', 12, 2)->default(0);
            $table->decimal('stamp_duty_amount', 6, 2)->default(0);

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_total', 12, 2)->default(0);
            // ImportoTotaleDocumento.
            $table->decimal('total', 12, 2)->default(0);
            // What the customer actually pays (net of withholding and split-payment VAT).
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['series', 'year', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
