<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment terms ("condizioni di pagamento"): method (FatturaPA MPxx code) and due date rule.
     */
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('method', 8);
            $table->unsignedSmallInteger('days')->default(0);
            $table->boolean('end_of_month')->default(false);
            $table->string('description', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('payment_terms')->insert(array_map(fn (array $term) => [
            'description' => null,
            'is_default' => false,
            'is_active' => true,
            'end_of_month' => false,
            'created_at' => $now,
            'updated_at' => $now,
            ...$term,
        ], [
            ['name' => 'Rimessa diretta', 'method' => 'MP01', 'days' => 0, 'sort' => 10],
            ['name' => 'Bonifico bancario a vista', 'method' => 'MP05', 'days' => 0, 'sort' => 20],
            ['name' => 'Bonifico bancario 30 gg d.f.', 'method' => 'MP05', 'days' => 30, 'is_default' => true, 'sort' => 30],
            ['name' => 'Bonifico bancario 30 gg d.f.f.m.', 'method' => 'MP05', 'days' => 30, 'end_of_month' => true, 'sort' => 40],
            ['name' => 'Bonifico bancario 60 gg d.f.f.m.', 'method' => 'MP05', 'days' => 60, 'end_of_month' => true, 'sort' => 50],
            ['name' => 'Ri.Ba. 30 gg d.f.f.m.', 'method' => 'MP12', 'days' => 30, 'end_of_month' => true, 'sort' => 60],
            ['name' => 'Ri.Ba. 60 gg d.f.f.m.', 'method' => 'MP12', 'days' => 60, 'end_of_month' => true, 'sort' => 70],
            ['name' => 'Carta di pagamento', 'method' => 'MP08', 'days' => 0, 'sort' => 80],
            ['name' => 'Addebito diretto SEPA', 'method' => 'MP19', 'days' => 0, 'sort' => 90],
        ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
