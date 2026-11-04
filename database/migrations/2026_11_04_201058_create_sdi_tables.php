<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Electronic invoicing through the Openapi SDI intermediary: transmissions of issued invoices,
     * events received (notifications, supplier invoices), received invoices and every API call with its cost.
     */
    public function up(): void
    {
        Schema::create('sdi_transmissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 16);
            $table->string('uuid', 64)->nullable()->index();
            $table->string('progressive', 5)->nullable();
            $table->string('filename', 64)->nullable();
            $table->string('status', 24)->index();
            $table->string('sdi_file_id', 32)->nullable();
            $table->json('errors')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('outcome_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('sdi_status', 24)->nullable()->index();
            $table->foreignId('sdi_transmission_id')->nullable()->constrained('sdi_transmissions')->nullOnDelete();
        });

        Schema::create('received_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16);
            $table->string('uuid', 64)->nullable()->unique();
            $table->string('hash', 64)->unique();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_vat', 32)->nullable()->index();
            $table->string('document_type', 8)->nullable();
            $table->string('number', 64)->nullable();
            $table->date('document_date')->nullable()->index();
            $table->decimal('total', 12, 2)->nullable();
            $table->string('file_path');
            $table->string('filename')->nullable();
            $table->string('status', 16)->default('new')->index();
            $table->foreignId('ai_extraction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sdi_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 48)->index();
            $table->string('type', 16)->nullable();
            $table->string('uuid', 64)->nullable()->index();
            $table->foreignId('sdi_transmission_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('received_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->string('result', 16)->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('openapi_calls', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 16);
            $table->string('method', 8);
            $table->string('endpoint', 120)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('success')->default(false);
            $table->decimal('price', 10, 4)->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('openapi_calls');
        Schema::dropIfExists('sdi_events');
        Schema::dropIfExists('received_invoices');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sdi_transmission_id');
            $table->dropColumn('sdi_status');
        });
        Schema::dropIfExists('sdi_transmissions');
    }
};
