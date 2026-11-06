<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expenses recorded in bulk from several documents read in the background.
     */
    public function up(): void
    {
        Schema::create('expense_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The files are read once all of them are uploaded.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('expense_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_import_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('file_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size')->default(0);
            // pending → processing → recorded | duplicate | failed
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('ai_extraction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('organizations')->nullOnDelete();
            // How the supplier was chosen: matched, created, generic.
            $table->string('supplier_source', 20)->nullable();
            $table->json('warnings')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_import_items');
        Schema::dropIfExists('expense_imports');
    }
};
