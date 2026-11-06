<?php

namespace Tests\Feature\Interventions;

use App\Enums\InterventionMode;
use App\Models\Intervention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SplitInterventionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * As written by the Dolibarr import: title, one line per job, public note.
     */
    private const DESCRIPTION = <<<'TEXT'
        Interventi da Aprile a Agosto 2026

        30/04/2026 14:30 · 4 h 30 min · Modifica database AGF
        04/05/2026 09:00 · 3 h 30 min · Ultimazione database
        caricamento in produzione
        21/05/2026 18:00 · 2 h · Tesar - Aggiornamenti

        Grazie per la collaborazione
        TEXT;

    public function test_the_jobs_listed_in_the_description_become_interventions(): void
    {
        $original = Intervention::factory()->create([
            'mode' => InterventionMode::Onsite,
            'callout' => true,
            'km' => 30,
            'started_at' => '2026-04-30 12:30:00',
            'duration_minutes' => 600,
            'description' => self::DESCRIPTION,
            'internal_notes' => 'Fatturato a fine lavori',
        ]);
        $original->materials()->create(['description' => 'Disco SSD', 'quantity' => 1, 'unit_price' => 80, 'unit' => 'pz']);

        $this->actingAs($this->admin)
            ->get("/interventions/{$original->id}")
            ->assertInertia(fn (Assert $page) => $page->where('splitCount', 3));

        $this->actingAs($this->admin)->post("/interventions/{$original->id}/split")->assertRedirect();

        $original->refresh();
        $this->assertSame('2026-04-30 12:30:00', $original->started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(270, $original->duration_minutes);
        $this->assertSame('Modifica database AGF', $original->description);
        $this->assertTrue($original->callout);
        $this->assertSame(1, $original->materials()->count());
        $this->assertStringContainsString('Interventi da Aprile a Agosto 2026', $original->internal_notes);

        $parts = Intervention::query()->where('split_from_id', $original->id)->orderBy('started_at')->get();
        $this->assertSame(['Ultimazione database'."\n".'caricamento in produzione', 'Tesar - Aggiornamenti'], $parts->pluck('description')->all());
        $this->assertSame([210, 120], $parts->pluck('duration_minutes')->all());
        $this->assertSame('2026-05-21 16:00:00', $parts[1]->started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertStringStartsWith('INT-2026-', $parts[0]->number);
        $this->assertFalse($parts[0]->callout);
        $this->assertSame(0, $parts[0]->materials()->count());
        $this->assertSame($original->organization_id, $parts[0]->organization_id);
        $this->assertStringContainsString("Dall'intervento {$original->number}.", $parts[0]->internal_notes);
        $this->assertStringContainsString('Grazie per la collaborazione', $parts[0]->internal_notes);
        $this->assertSame(600, $original->duration_minutes + $parts->sum('duration_minutes'));

        // Nothing left to split.
        $this->actingAs($this->admin)
            ->get("/interventions/{$original->id}")
            ->assertInertia(fn (Assert $page) => $page->where('splitCount', 0));
    }

    public function test_signed_interventions_are_not_split(): void
    {
        $intervention = Intervention::factory()->create(['description' => self::DESCRIPTION]);
        $intervention->forceFill(['signed_at' => now(), 'signed_by_name' => 'Mario Rossi'])->save();

        $this->actingAs($this->admin)->post("/interventions/{$intervention->id}/split")->assertForbidden();
        $this->assertSame(1, Intervention::count());
    }
}
