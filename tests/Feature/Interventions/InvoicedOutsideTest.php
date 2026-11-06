<?php

namespace Tests\Feature\Interventions;

use App\Enums\InterventionStatus;
use App\Enums\Permission;
use App\Models\Intervention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoicedOutsideTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-06 10:00:00');
        $this->admin = User::factory()->admin()->create();
    }

    public function test_interventions_up_to_a_day_are_marked_as_invoiced_outside_prugna(): void
    {
        $old = Intervention::factory()->create(['started_at' => '2024-03-10 08:00:00']);
        // 31 December at 23:30 in Italy is still 2025 (22:30 UTC).
        $lastDay = Intervention::factory()->create(['started_at' => '2025-12-31 22:30:00']);
        $straddling = Intervention::factory()->create([
            'started_at' => '2025-12-15 08:00:00',
            'description' => "15/12/2025 09:00 · 3 h · Migrazione server\n12/01/2026 14:00 · 2 h · Verifiche",
        ]);
        $current = Intervention::factory()->create(['started_at' => '2026-01-05 08:00:00']);
        Intervention::factory()->create(['started_at' => '2025-05-05 08:00:00', 'billable' => false]);

        $preview = $this->actingAs($this->admin)->getJson('/interventions/invoiced-outside?until=2025-12-31')->assertOk()->json('data');
        $this->assertSame([$old->id, $straddling->id, $lastDay->id], array_column($preview, 'id'));
        $this->assertSame([0, 1, 0], array_column($preview, 'later_jobs'));

        $this->actingAs($this->admin)
            ->post('/interventions/invoiced-outside', ['until' => '2025-12-31', 'interventions' => [$old->id, $lastDay->id, $current->id]])
            ->assertRedirect();

        $this->assertSame(InterventionStatus::Invoiced, $old->refresh()->status());
        $this->assertTrue($lastDay->refresh()->isInvoicedOutsidePrugna());
        $this->assertFalse($current->refresh()->isInvoicedOutsidePrugna());
        $this->assertSame([$straddling->id, $current->id], Intervention::query()->toInvoice()->orderBy('started_at')->pluck('id')->all());
        $this->assertSame([$old->id, $lastDay->id], Intervention::query()->withStatus('invoiced')->orderBy('started_at')->pluck('id')->all());

        // Frozen and never proposed again.
        $this->actingAs($this->admin)->get("/interventions/{$old->id}/edit")->assertForbidden();
        $this->actingAs($this->admin)
            ->getJson("/sales/invoices/lookup/interventions?organization_id={$old->organization_id}")
            ->assertJsonMissing(['id' => $old->id]);
    }

    public function test_the_mark_can_be_removed(): void
    {
        $intervention = Intervention::factory()->create(['started_at' => '2025-03-10 08:00:00']);
        $intervention->forceFill(['invoiced_outside_at' => now()])->save();

        $this->actingAs($this->admin)
            ->get("/interventions/{$intervention->id}")
            ->assertInertia(fn (Assert $page) => $page->where('intervention.can.unmark_invoiced', true)->where('intervention.status', 'invoiced'));

        $this->actingAs($this->admin)->delete("/interventions/{$intervention->id}/invoiced-outside")->assertRedirect();

        $this->assertSame(InterventionStatus::ToInvoice, $intervention->refresh()->status());
    }

    public function test_marking_needs_the_invoices_permission(): void
    {
        $intervention = Intervention::factory()->create(['started_at' => '2025-03-10 08:00:00']);
        $supervisor = User::factory()->create();
        $supervisor->givePermissionTo(Permission::InterventionsSupervise->value, Permission::InterventionsManage->value);

        $this->actingAs($supervisor)->getJson('/interventions/invoiced-outside?until=2025-12-31')->assertForbidden();
        $this->actingAs($supervisor)->post('/interventions/invoiced-outside', ['until' => '2025-12-31', 'interventions' => [$intervention->id]])->assertForbidden();
        $this->assertFalse($intervention->refresh()->isInvoicedOutsidePrugna());
    }
}
