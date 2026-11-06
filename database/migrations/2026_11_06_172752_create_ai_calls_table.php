<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every OpenRouter call with what it cost, for «Costi IA». The AI readings made so far
     * (ai_extractions read by the AI) are copied in, so the history starts complete.
     */
    public function up(): void
    {
        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // expense, organization, product_link, product_screenshot, advantages, test
            $table->string('feature', 40)->index();
            $table->string('model')->nullable();
            $table->boolean('success')->default(true);
            // USD, as charged by OpenRouter.
            $table->decimal('cost', 12, 6)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        DB::table('ai_extractions')->where('source', 'ai')->orderBy('id')->chunk(500, function ($extractions): void {
            DB::table('ai_calls')->insert($extractions->map(fn ($extraction) => [
                'user_id' => $extraction->user_id,
                'feature' => $extraction->kind,
                'model' => $extraction->model,
                'success' => $extraction->status === 'succeeded',
                'cost' => $extraction->cost,
                'prompt_tokens' => $extraction->prompt_tokens,
                'completion_tokens' => $extraction->completion_tokens,
                'duration_ms' => $extraction->duration_ms,
                'error' => $extraction->error,
                'created_at' => $extraction->created_at,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_calls');
    }
};
