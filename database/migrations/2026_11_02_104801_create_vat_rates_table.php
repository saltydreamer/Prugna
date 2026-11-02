<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vat_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('rate', 5, 2);
            // FatturaPA "Natura" (N1…N7): required when the rate is 0.
            $table->string('nature', 8)->nullable();
            // Legal reference printed on documents for exempt operations.
            $table->string('legal_reference')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('vat_rates')->insert(array_map(fn (array $rate) => [
            'nature' => null,
            'legal_reference' => null,
            'is_default' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
            ...$rate,
        ], [
            ['name' => 'IVA 22%', 'rate' => 22, 'is_default' => true, 'sort' => 10],
            ['name' => 'IVA 10%', 'rate' => 10, 'sort' => 20],
            ['name' => 'IVA 5%', 'rate' => 5, 'sort' => 30],
            ['name' => 'IVA 4%', 'rate' => 4, 'sort' => 40],
            ['name' => 'Escluso art. 15', 'rate' => 0, 'nature' => 'N1', 'legal_reference' => 'Escluso art. 15 DPR 633/72', 'sort' => 50],
            ['name' => 'Non soggetto (servizi UE/extra UE)', 'rate' => 0, 'nature' => 'N2.1', 'legal_reference' => 'Non soggetto art. 7-ter DPR 633/72', 'sort' => 60],
            ['name' => 'Non soggetto (forfettario)', 'rate' => 0, 'nature' => 'N2.2', 'legal_reference' => 'Operazione senza applicazione dell\'IVA ai sensi dell\'art. 1, commi 54-89, L. 190/2014', 'sort' => 70],
            ['name' => 'Non imponibile esportazioni', 'rate' => 0, 'nature' => 'N3.1', 'legal_reference' => 'Non imponibile art. 8 DPR 633/72', 'sort' => 80],
            ['name' => 'Non imponibile cessioni UE', 'rate' => 0, 'nature' => 'N3.2', 'legal_reference' => 'Non imponibile art. 41 DL 331/93', 'sort' => 90],
            ['name' => 'Esente art. 10', 'rate' => 0, 'nature' => 'N4', 'legal_reference' => 'Esente art. 10 DPR 633/72', 'sort' => 100],
            ['name' => 'Reverse charge edilizia', 'rate' => 0, 'nature' => 'N6.7', 'legal_reference' => 'Inversione contabile art. 17 c. 6 lett. a) DPR 633/72', 'sort' => 110],
            ['name' => 'Reverse charge altri casi', 'rate' => 0, 'nature' => 'N6.9', 'legal_reference' => 'Inversione contabile art. 17 DPR 633/72', 'sort' => 120],
        ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vat_rates');
    }
};
