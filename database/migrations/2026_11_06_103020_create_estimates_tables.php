<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preventivatore (experimental): items at cost, grouped in free categories, sold with a markup
     * as a monthly fee over a fixed term (36 months), with a report for the customer.
     */
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('number', 32)->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('intro')->nullable();
            $table->unsignedSmallInteger('months')->default(36);
            $table->decimal('markup_percent', 6, 2)->default(30);
            // Markup by category name: {"Rete": 25}.
            $table->json('category_markups')->nullable();
            $table->decimal('upfront', 12, 2)->default(0);
            $table->date('starts_on')->nullable();
            $table->text('advantages')->nullable();
            $table->text('notes')->nullable();
            // draft / accepted
            $table->string('status', 16)->default('draft');
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['year', 'sequence']);
        });

        Schema::create('estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('category', 60)->nullable();
            $table->string('description', 500);
            $table->string('url', 2000)->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_cost', 12, 4)->default(0);
            // How the cost was found (e.g. read by the AI from the link, VAT added).
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_items');
        Schema::dropIfExists('estimates');
    }
};
