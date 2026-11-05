<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contratti servizi: recurring services charged to customers, their lines and the fees
     * (periods) already put in an invoice.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('number', 32)->unique();
            $table->foreignId('organization_id')->constrained();
            $table->string('title');
            $table->string('billing_period', 16);
            // advance: invoiced when the period starts; arrears: when it ends.
            $table->string('billing_mode', 16)->default('advance');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            // Fees invoiced outside Prugna (e.g. Dolibarr) up to this date.
            $table->date('billed_until')->nullable();
            $table->foreignId('payment_term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['year', 'sequence']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('subscription_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
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
            $table->timestamps();
        });

        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            // Deleting the invoice (a draft, or an issued one never sent) puts the fee back to invoice.
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['subscription_id', 'starts_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_periods');
        Schema::dropIfExists('subscription_lines');
        Schema::dropIfExists('subscriptions');
    }
};
