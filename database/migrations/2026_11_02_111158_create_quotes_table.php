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
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sequence')->unique();
            $table->string('number', 32)->unique();
            $table->string('status', 16)->default('draft')->index();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            // Customer data frozen on the document (name, VAT number, address...).
            $table->json('customer');
            $table->string('title')->nullable();
            $table->date('issue_date');
            $table->date('valid_until')->index();
            $table->foreignId('payment_term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_terms')->nullable();
            $table->text('intro')->nullable();
            $table->text('terms')->nullable();
            $table->text('internal_notes')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            // Unguessable token of the public page where the customer accepts or rejects.
            $table->string('public_token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_name')->nullable();
            $table->text('decision_note')->nullable();
            $table->string('decision_ip', 45)->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
