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
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32)->default('operational');
            $table->boolean('is_primary')->default(false);
            $table->string('address');
            $table->string('postal_code', 16)->nullable();
            $table->string('city');
            $table->string('province', 8)->nullable();
            $table->char('country', 2)->default('IT');
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'is_primary']);
            $table->index('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
