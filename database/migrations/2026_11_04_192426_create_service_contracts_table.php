<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prepaid hour packages ("contratti a monte ore"): covered interventions use up the hours instead of being invoiced.
     */
    public function up(): void
    {
        Schema::create('service_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('number', 32)->unique();
            $table->foreignId('organization_id')->constrained();
            $table->string('title');
            $table->decimal('hours', 8, 2);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            // null: both modes; categories null/empty: all.
            $table->string('mode', 16)->nullable();
            $table->json('category_ids')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vat_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamp('alerted_threshold_at')->nullable();
            $table->timestamp('alerted_exhausted_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'sequence']);
            $table->index(['organization_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_contracts');
    }
};
