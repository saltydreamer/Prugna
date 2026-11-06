<?php

namespace Tests\Feature\Interventions;

use App\Actions\Interventions\ApplyContract;
use App\Enums\Role;
use App\Mail\DocumentMail;
use App\Models\Intervention;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\ServiceContract;
use App\Models\User;
use App\Notifications\ContractHoursNotification;
use App\Support\InterventionSummaryPdf;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class InterventionReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** 1×1 transparent PNG. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_report_is_printed_and_signed(): void
    {
        Storage::fake('local');
        $intervention = Intervention::factory()->onsite()->create(['technician_id' => $this->admin->id, 'internal_notes' => 'Nota segreta']);

        $this->actingAs($this->admin)->get("/interventions/{$intervention->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->admin)->post("/interventions/{$intervention->id}/signature", ['signed_by_name' => 'Mario Rossi', 'signature' => 'data:image/png;base64,bm90IGEgcG5n'])
            ->assertSessionHasErrors('signature');

        $this->actingAs($this->admin)->post("/interventions/{$intervention->id}/signature", ['signed_by_name' => 'Mario Rossi', 'signature' => self::PNG])->assertRedirect();

        $intervention->refresh();
        $this->assertTrue($intervention->isSigned());
        $this->assertSame('Mario Rossi', $intervention->signed_by_name);
        Storage::disk('local')->assertExists($intervention->signature_path);
        $this->actingAs($this->admin)->get("/interventions/{$intervention->id}/signature")->assertOk();
        $this->actingAs($this->admin)->get("/interventions/{$intervention->id}/pdf")->assertOk();

        // Signed: final, can't be signed twice; supervisors can remove the signature.
        $this->actingAs($this->admin)->put("/interventions/{$intervention->id}", [])->assertForbidden();
        $this->actingAs($this->admin)->post("/interventions/{$intervention->id}/signature", ['signed_by_name' => 'X', 'signature' => self::PNG])->assertForbidden();
        $this->actingAs($this->admin)->delete("/interventions/{$intervention->id}/signature")->assertRedirect();
        $this->assertFalse($intervention->refresh()->isSigned());
    }

    public function test_the_selected_interventions_are_summarised_in_a_pdf(): void
    {
        $this->travelTo('2026-11-06 12:00:00');
        $acme = Organization::factory()->create(['name' => 'Acme Srl']);
        $other = Organization::factory()->create(['name' => 'Beta Spa']);
        $remote = Intervention::factory()->create(['organization_id' => $acme->id, 'started_at' => '2026-11-03 08:00:00', 'duration_minutes' => 50, 'description' => 'Configurazione della posta', 'internal_notes' => 'Nota segreta']);
        $onsite = Intervention::factory()->onsite()->create(['organization_id' => $acme->id, 'started_at' => '2026-11-04 13:00:00', 'duration_minutes' => 120, 'travel_minutes' => 40, 'km' => 32, 'description' => 'Sostituzione switch']);
        $onsite->materials()->create(['position' => 1, 'description' => 'Switch 8 porte', 'unit' => 'pz', 'quantity' => 1, 'unit_price' => 40]);
        $running = Intervention::factory()->running()->create(['organization_id' => $acme->id]);
        $otherCustomer = Intervention::factory()->create(['organization_id' => $other->id, 'started_at' => '2026-11-05 08:00:00']);

        $this->actingAs($this->admin)->get('/interventions/summary?'.http_build_query(['ids' => [$remote->id, $onsite->id, $running->id], 'download' => 1]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename=Riepilogo-interventi-acme-srl-2026-11-06.pdf');

        $html = app(InterventionSummaryPdf::class)->make(Intervention::query()->whereIn('id', [$remote->id, $onsite->id])->get())->getDomPDF()->outputHtml();
        $this->assertStringContainsString('Riepilogo interventi', $html);
        $this->assertStringContainsString('Acme Srl', $html);
        $this->assertStringContainsString($remote->number, $html);
        $this->assertStringContainsString('Sostituzione switch', $html);
        $this->assertStringContainsString('Switch 8 porte (1 pz)', $html);
        $this->assertStringContainsString('2 h 50 min', $html);
        $this->assertStringContainsString('32 km', $html);
        $this->assertStringNotContainsString('Nota segreta', $html);
        $this->assertStringNotContainsString($running->number, $html);

        // Several customers: one section each.
        $html = app(InterventionSummaryPdf::class)->make(Intervention::query()->whereIn('id', [$remote->id, $otherCustomer->id])->get())->getDomPDF()->outputHtml();
        $this->assertStringContainsString('Totale Acme Srl', $html);
        $this->assertStringContainsString('Totale Beta Spa', $html);

        $this->actingAs($this->admin)->get('/interventions/summary')->assertSessionHasErrors('ids');
        $this->actingAs($this->admin)->get('/interventions/summary?'.http_build_query(['ids' => [$running->id]]))->assertNotFound();
    }

    public function test_all_the_filtered_interventions_are_summarised_also_the_covered_ones(): void
    {
        $this->travelTo('2026-11-06 12:00:00');
        $acme = Organization::factory()->create(['name' => 'Acme Srl']);
        $covered = Intervention::factory()->count(2)->create(['organization_id' => $acme->id, 'started_at' => '2026-11-03 08:00:00', 'covered' => true]);
        $toInvoice = Intervention::factory()->create(['organization_id' => $acme->id, 'started_at' => '2026-11-04 08:00:00']);
        Intervention::factory()->running()->create(['organization_id' => $acme->id]);
        Intervention::factory()->create(['organization_id' => $acme->id, 'started_at' => '2026-10-20 08:00:00']);
        Intervention::factory()->create(['started_at' => '2026-11-03 08:00:00']);

        $expected = [...$covered->pluck('id'), $toInvoice->id];
        $pdf = Mockery::mock(InterventionSummaryPdf::class, [app(Settings::class)])->makePartial();
        $pdf->shouldReceive('make')->once()->withArgs(fn (Collection $interventions) => $interventions->pluck('id')->sort()->values()->all() === $expected)->passthru();
        $this->instance(InterventionSummaryPdf::class, $pdf);

        $this->actingAs($this->admin)->get('/interventions/summary?'.http_build_query(['all' => 1, 'customer' => $acme->id, 'from' => '2026-11-01', 'download' => 1]))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=Riepilogo-interventi-acme-srl-2026-11-06.pdf');

        // The status tab is a filter too.
        $this->actingAs($this->admin)->get('/interventions/summary?'.http_build_query(['all' => 1, 'status' => 'covered', 'customer' => $acme->id, 'to' => '2026-10-31']))->assertNotFound();
    }

    public function test_technicians_summarise_only_their_own_interventions(): void
    {
        $technician = User::factory()->withRole(Role::User)->create();
        $own = Intervention::factory()->create(['technician_id' => $technician->id]);
        $colleague = Intervention::factory()->create();

        $this->actingAs($technician)->get('/interventions/summary?'.http_build_query(['ids' => [$colleague->id]]))->assertNotFound();
        $this->actingAs($technician)->get('/interventions/summary?'.http_build_query(['ids' => [$own->id, $colleague->id]]))->assertOk();
    }

    public function test_running_interventions_cannot_be_signed(): void
    {
        $intervention = Intervention::factory()->running()->create(['technician_id' => $this->admin->id]);

        $this->actingAs($this->admin)->post("/interventions/{$intervention->id}/signature", ['signed_by_name' => 'Mario', 'signature' => self::PNG])->assertForbidden();
    }

    public function test_the_report_is_sent_by_email(): void
    {
        Mail::fake();
        app(Settings::class)->set(['mail.enabled' => true, 'mail.host' => 'smtp.example.com', 'mail.from_address' => 'noreply@example.com']);
        $intervention = Intervention::factory()->create(['technician_id' => $this->admin->id]);

        $this->actingAs($this->admin)->getJson("/interventions/{$intervention->id}/compose")->assertOk()->assertJsonPath('subject', fn (string $subject) => str_contains($subject, $intervention->number));

        $this->actingAs($this->admin)->post("/interventions/{$intervention->id}/send", [
            'to' => ['cliente@example.com'],
            'subject' => 'Rapportino',
            'body' => 'In allegato',
            'attach_pdf' => true,
        ])->assertRedirect();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail) => $mail->hasTo('cliente@example.com') && $mail->filename === "Rapportino-{$intervention->number}.pdf");
        $this->assertNotNull($intervention->refresh()->report_sent_at);
    }

    public function test_contract_hours_are_used_before_invoicing(): void
    {
        Notification::fake();
        $customer = Organization::factory()->create();
        $contract = ServiceContract::factory()->create(['organization_id' => $customer->id, 'hours' => 2, 'manager_id' => $this->admin->id]);
        $categoryId = Intervention::factory()->make()->category_id;

        $payload = fn (int $minutes, array $overrides = []) => [
            'organization_id' => $customer->id,
            'category_id' => $categoryId,
            'mode' => 'remote',
            'started_at' => now()->subDay()->format('Y-m-d\TH:i'),
            'duration_minutes' => $minutes,
            'billable' => true,
            ...$overrides,
        ];

        // 50 min → 1 h from the contract: covered, nothing to invoice.
        $this->actingAs($this->admin)->post('/interventions', $payload(50))->assertRedirect();
        $first = Intervention::latest('id')->first();
        $this->assertSame($contract->id, $first->contract_id);
        $this->assertSame(60, $first->contract_minutes);
        $this->assertSame('covered', $first->status()->value);
        Notification::assertNothingSent();

        // 90 min → 1,5 h: 1 h left on the contract, 0,5 h to invoice; contract exhausted.
        $this->actingAs($this->admin)->post('/interventions', $payload(90))->assertRedirect();
        $second = Intervention::latest('id')->first();
        $this->assertSame(60, $second->contract_minutes);
        $this->assertSame(30, $second->invoiceableMinutes());
        $this->assertSame('to_invoice', $second->status()->value);
        $this->assertSame(0, $contract->refresh()->remainingMinutes());
        Notification::assertSentTo($this->admin, ContractHoursNotification::class, fn (ContractHoursNotification $notification) => $notification->exhausted);

        // Skipping the contract invoices everything.
        $this->actingAs($this->admin)->post('/interventions', $payload(30, ['skip_contract' => true]))->assertRedirect();
        $this->assertNull(Intervention::latest('id')->first()->contract_id);

        $this->actingAs($this->admin)->getJson("/sales/invoices/lookup/interventions?organization_id={$customer->id}")
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.invoiceable_minutes', 30);

        $this->actingAs($this->admin)->post('/sales/invoices/from-interventions', ['organization_id' => $customer->id, 'interventions' => [$second->id]])->assertRedirect();
        $line = Invoice::sole()->lines()->sole();
        $this->assertSame('0.500', $line->quantity);
        $this->assertStringContainsString($contract->number, $line->description);

        // Deleting an intervention gives the hours back.
        $first->delete();
        $this->assertSame(60, $contract->refresh()->remainingMinutes());
    }

    public function test_the_threshold_alert_is_sent_once(): void
    {
        Notification::fake();
        $contract = ServiceContract::factory()->create(['hours' => 5, 'manager_id' => $this->admin->id]);

        $intervention = Intervention::factory()->create(['organization_id' => $contract->organization_id, 'duration_minutes' => 240]);
        app(ApplyContract::class)->handle($intervention);

        Notification::assertSentToTimes($this->admin, ContractHoursNotification::class, 1);
        $this->assertNotNull($contract->refresh()->alerted_threshold_at);

        app(ApplyContract::class)->handle($intervention->refresh());
        Notification::assertSentToTimes($this->admin, ContractHoursNotification::class, 1);
    }

    public function test_contracts_cover_only_their_modes_categories_and_period(): void
    {
        $contract = ServiceContract::factory()->create(['mode' => 'onsite']);
        $remote = Intervention::factory()->create(['organization_id' => $contract->organization_id]);
        app(ApplyContract::class)->handle($remote);
        $this->assertNull($remote->refresh()->contract_id);

        $contract->update(['mode' => null, 'ends_on' => today()->subMonths(2), 'starts_on' => today()->subYear()]);
        app(ApplyContract::class)->handle($remote);
        $this->assertNull($remote->refresh()->contract_id);
    }

    public function test_a_contract_starting_today_covers_todays_interventions(): void
    {
        // 23:30 in Rome is still "today" there, while UTC midnight of the start date is earlier.
        $this->travelTo(now('Europe/Rome')->setTime(23, 30)->utc());
        $contract = ServiceContract::factory()->create(['starts_on' => now('Europe/Rome')->toDateString(), 'ends_on' => null]);
        $this->assertSame('active', $contract->status());

        $intervention = Intervention::factory()->create(['organization_id' => $contract->organization_id, 'started_at' => now()->subHour()]);
        app(ApplyContract::class)->handle($intervention);

        $this->assertSame($contract->id, $intervention->refresh()->contract_id);
    }

    public function test_the_package_is_invoiced_once(): void
    {
        $contract = ServiceContract::factory()->create(['price' => 450]);

        $this->actingAs($this->admin)->get('/interventions/contracts')->assertOk();
        $this->actingAs($this->admin)->get("/interventions/contracts/{$contract->id}")->assertOk();

        $this->actingAs($this->admin)->post("/interventions/contracts/{$contract->id}/invoice")->assertRedirect();

        $invoice = Invoice::sole();
        $this->assertSame('450.00', $invoice->subtotal);
        $this->assertSame($invoice->id, $contract->refresh()->invoice_id);

        $this->actingAs($this->admin)->post("/interventions/contracts/{$contract->id}/invoice")->assertStatus(409);
    }

    public function test_contracts_are_created_with_a_number(): void
    {
        $customer = Organization::factory()->create();

        $this->actingAs($this->admin)->post('/interventions/contracts', [
            'organization_id' => $customer->id,
            'title' => 'Pacchetto 20 ore',
            'hours' => 20,
            'starts_on' => today()->toDateString(),
            'price' => 800,
        ])->assertRedirect();

        $this->assertSame('MO-'.today()->year.'-0001', ServiceContract::sole()->number);
    }
}
