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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 32)->default('company')->index();
            $table->string('vat_number', 32)->nullable()->index();
            $table->string('tax_code', 32)->nullable()->index();
            $table->string('sdi_code', 7)->nullable();
            $table->string('email')->nullable();
            $table->string('pec')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
