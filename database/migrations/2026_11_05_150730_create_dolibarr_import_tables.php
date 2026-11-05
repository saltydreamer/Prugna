<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each uploaded Dolibarr backup: simulation first, then the real import, with their reports.
        Schema::create('dolibarr_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            // uploaded → simulating → simulated → importing → imported | failed
            $table->string('status', 16)->index();
            $table->json('report')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        // Dolibarr row → Prugna record, so that importing again updates instead of duplicating.
        Schema::create('dolibarr_links', function (Blueprint $table) {
            $table->id();
            // Source table, with the role when one row becomes more records (e.g. "societe.customer").
            $table->string('source', 48);
            $table->unsignedBigInteger('source_id');
            $table->morphs('linkable');
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->unique(['source', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dolibarr_links');
        Schema::dropIfExists('dolibarr_imports');
    }
};
