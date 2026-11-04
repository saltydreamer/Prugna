<?php

namespace Tests\Feature\Sdi;

use App\Actions\Invoices\IssueInvoice;
use App\Actions\Invoices\SaveInvoice;
use App\Actions\Sdi\SendInvoiceToSdi;
use App\Enums\SdiStatus;
use App\Jobs\ProcessSdiEvent;
use App\Models\Invoice;
use App\Models\OpenapiCall;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\ReceivedInvoice;
use App\Models\User;
use App\Models\VatRate;
use App\Notifications\SdiOutcomeNotification;
use App\Support\FatturaPa\FatturaPaXml;
use App\Support\Sdi\OpenapiClient;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class OpenapiSdiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const SECRET = 'segreto-di-prova';

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        Storage::fake('local');

        app(Settings::class)->set([
            'company.name' => 'Reimg Srl',
            'company.vat_number' => '01234567897',
            'company.tax_code' => '01234567897',
            'company.address' => 'Via Roma 1',
            'company.postal_code' => '20121',
            'company.city' => 'Milano',
            'company.province' => 'MI',
            'company.email' => 'amministrazione@example.com',
            'sdi.enabled' => true,
            'sdi.environment' => 'test',
            'sdi.username' => 'marco@example.com',
            'sdi.api_key' => 'api-key',
            'sdi.webhook_secret' => self::SECRET,
            'sdi.auto_send' => true,
        ]);
    }

    private function fakeOpenapi(array $extra = []): void
    {
        Http::fake([
            'test.oauth.openapi.it/scopes*' => Http::response(['data' => ['POST:test.sdi.openapi.it/invoices', 'GET:test.sdi.openapi.it/invoices_download', 'GET:test.imprese.openapi.it/advance']]),
            'test.oauth.openapi.it/token' => Http::response(['token' => 'tok-123', 'success' => true]),
            ...$extra,
        ]);
    }

    private function draft(): Invoice
    {
        return app(SaveInvoice::class)->handle(new Invoice, [
            'organization_id' => Organization::factory()->withDetails(1, 1)->create(['sdi_code' => 'ABC1234'])->id,
            'issue_date' => today()->toDateString(),
            'payment_term_id' => PaymentTerm::default()->id,
            'owner_id' => $this->admin->id,
            'lines' => [['type' => 'item', 'description' => 'Assistenza', 'quantity' => 1, 'unit_price' => 100, 'vat_rate_id' => VatRate::default()->id]],
        ]);
    }

    private function webhook(array $payload, string $secret = self::SECRET)
    {
        return $this->postJson('/webhooks/openapi/sdi', $payload, ['Authorization' => 'Bearer '.$secret]);
    }

    public function test_connecting_registers_the_company_and_the_callbacks(): void
    {
        $this->fakeOpenapi([
            'test.sdi.openapi.it/business_registry_configurations' => Http::sequence()->push(['data' => []])->push(['data' => ['fiscal_id' => '01234567897']]),
            'test.sdi.openapi.it/api_configurations' => Http::sequence()->push(['data' => []])->push(['data' => ['id' => 'cfg1']]),
        ]);

        $this->actingAs($this->admin)->post('/settings/einvoicing/connect')->assertRedirect();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://test.sdi.openapi.it/business_registry_configurations' && $request->method() === 'POST'
            && $request['fiscal_id'] === '01234567897' && $request['apply_legal_storage'] === false && $request->hasHeader('Authorization', 'Bearer tok-123'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://test.sdi.openapi.it/api_configurations' && $request->method() === 'POST'
            && count($request['callbacks']) === 3 && str_contains($request['callbacks'][0]['url'], '/webhooks/openapi/sdi')
            && $request['callbacks'][0]['auth_header'] === 'Bearer '.self::SECRET);
        // The token asks only for the SDI scopes of the environment.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/token') && $request['scopes'] === ['POST:test.sdi.openapi.it/invoices', 'GET:test.sdi.openapi.it/invoices_download']);

        $this->assertNotNull(app(Settings::class)->get('sdi.connected_at'));
    }

    public function test_issued_invoices_are_sent_and_follow_the_sdi_outcome(): void
    {
        Notification::fake();
        $this->fakeOpenapi(['test.sdi.openapi.it/invoices' => Http::sequence()->push(['data' => ['uuid' => 'uuid-1']])->push(['data' => ['uuid' => 'uuid-2']])]);
        $invoice = $this->draft();

        $this->actingAs($this->admin)->post("/sales/invoices/{$invoice->id}/issue")->assertRedirect();

        $invoice->refresh();
        $transmission = $invoice->sdiTransmission;
        $this->assertSame(SdiStatus::Sent, $invoice->sdi_status);
        $this->assertSame('uuid-1', $transmission->uuid);
        $this->assertSame('S0001', substr($transmission->progressive, 0, 5));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://test.sdi.openapi.it/invoices' && str_contains($request->body(), '<ProgressivoInvio>'.$transmission->progressive.'</ProgressivoInvio>'));

        $call = OpenapiCall::query()->where('endpoint', 'POST /invoices')->sole();
        $this->assertTrue($call->success);
        $this->assertSame('0.0000', $call->price, 'The sandbox is free.');

        // Wrong secret: refused.
        $this->webhook(['event' => 'customer-notification'], 'sbagliato')->assertUnauthorized();

        // Rejected by the SDI, with errors: the owner is told.
        $this->webhook(['event' => 'customer-notification', 'data' => ['notification' => [
            'invoice_uuid' => 'uuid-1', 'type' => 'NS', 'created_at' => now()->toIso8601String(),
            'message' => ['lista_errori' => ['Errore' => [['Codice' => '00311', 'Descrizione' => 'CodiceDestinatario non valido']]]],
        ]]])->assertOk();

        $transmission->refresh();
        $this->assertSame(SdiStatus::Rejected, $transmission->status);
        $this->assertSame(['00311 – CodiceDestinatario non valido'], $transmission->errors);
        $this->assertSame(SdiStatus::Rejected, $invoice->refresh()->sdi_status);
        Notification::assertSentTo($this->admin, SdiOutcomeNotification::class);

        // Sent again: a new file, then delivered.
        $this->actingAs($this->admin)->post("/sales/invoices/{$invoice->id}/sdi/send")->assertRedirect();
        $second = $invoice->refresh()->sdiTransmission;
        $this->assertNotSame($transmission->progressive, $second->progressive);

        $this->webhook(['event' => 'customer-notification', 'data' => ['notification' => ['invoice_uuid' => 'uuid-2', 'type' => 'RC', 'created_at' => now()->toIso8601String()]]])->assertOk();
        $this->assertSame(SdiStatus::Delivered, $invoice->refresh()->sdi_status);

        // Delivered: can't be sent again.
        $this->actingAs($this->admin)->post("/sales/invoices/{$invoice->id}/sdi/send")->assertStatus(409);
        $this->actingAs($this->admin)->get("/sales/invoices/{$invoice->id}")->assertInertia(fn (Assert $page) => $page->has('sdi.transmissions', 2));
    }

    public function test_production_calls_are_priced_from_the_price_list(): void
    {
        app(Settings::class)->set(['sdi.environment' => 'production', 'sdi.prices' => ['POST /invoices' => 0.05]]);
        Http::fake([
            'oauth.openapi.it/scopes*' => Http::response(['data' => ['POST:sdi.openapi.it/invoices', 'POST:test.sdi.openapi.it/invoices']]),
            'oauth.openapi.it/token' => Http::response(['token' => 'tok-prod']),
            'sdi.openapi.it/invoices' => Http::response(['data' => ['uuid' => 'p-1']]),
        ]);

        $invoice = app(IssueInvoice::class)->handle($this->draft());
        app(SendInvoiceToSdi::class)->handle($invoice);

        $this->assertSame('0.0500', OpenapiCall::query()->where('endpoint', 'POST /invoices')->sole()->price);
        $this->actingAs($this->admin)->get('/settings/einvoicing/costs')->assertInertia(fn (Assert $page) => $page
            ->component('einvoicing/Costs')
            ->where('summary.month_total', 0.05)
            ->where('summary.invoices_sent', 1));
    }

    public function test_failed_sends_are_reported_and_can_be_retried(): void
    {
        $this->fakeOpenapi(['test.sdi.openapi.it/invoices' => Http::response(['message' => 'XML non valido'], 422)]);
        $invoice = app(IssueInvoice::class)->handle($this->draft());

        $transmission = app(SendInvoiceToSdi::class)->handle($invoice);

        $this->assertSame(SdiStatus::Error, $transmission->status);
        $this->assertStringContainsString('XML non valido', $transmission->message);
        $this->assertFalse(OpenapiCall::query()->sole()->success);
        $this->assertTrue($transmission->status->canResend());
    }

    public function test_supplier_invoices_arrive_in_the_inbox_and_become_expenses(): void
    {
        $xml = app(FatturaPaXml::class)->make(app(IssueInvoice::class)->handle($this->draft()));
        $this->fakeOpenapi(['test.sdi.openapi.it/invoices_download/*' => Http::response($xml, 200, ['Content-Type' => 'application/xml'])]);

        $this->webhook(['event' => 'supplier-invoice', 'data' => ['invoice' => ['uuid' => 'in-1']]])->assertOk();
        // The same callback twice: stored once.
        $this->webhook(['event' => 'supplier-invoice', 'data' => ['invoice' => ['uuid' => 'in-1']]])->assertOk();

        $received = ReceivedInvoice::sole();
        $this->assertSame('Reimg Srl', $received->supplier_name);
        $this->assertSame('122.00', $received->total);
        Storage::disk('local')->assertExists($received->file_path);

        $this->actingAs($this->admin)->get('/purchases/received-invoices')->assertInertia(fn (Assert $page) => $page->component('purchases/received/Index')->has('invoices.data', 1));

        $response = $this->actingAs($this->admin)->post("/purchases/received-invoices/{$received->id}/record");
        $extractionId = $received->refresh()->ai_extraction_id;
        $response->assertRedirect("/purchases/expenses/create?extraction={$extractionId}");

        $supplier = Organization::factory()->supplier()->create();
        $this->actingAs($this->admin)->post('/purchases/expenses', [
            'type' => 'invoice',
            'supplier_id' => $supplier->id,
            'number' => 'FT-1',
            'document_date' => today()->toDateString(),
            'ai_extraction_id' => $extractionId,
            'lines' => [['type' => 'item', 'description' => 'Assistenza', 'quantity' => 1, 'unit_price' => 100, 'vat_rate_id' => VatRate::default()->id]],
        ])->assertRedirect();

        $this->assertSame(ReceivedInvoice::STATUS_RECORDED, $received->refresh()->status);
        $this->assertNotNull($received->expense_id);
    }

    public function test_files_from_fatture_e_corrispettivi_are_imported(): void
    {
        $first = app(FatturaPaXml::class)->make(app(IssueInvoice::class)->handle($this->draft()));
        $second = app(FatturaPaXml::class)->make(app(IssueInvoice::class)->handle($this->draft()));

        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString('IT01234567897_A0001.xml', $first);
        $zip->addFromString('IT01234567897_A0001_MT_001.xml', '<FileMetadati/>');
        $zip->addFromString('IT01234567897_A0002.xml', $second);
        $zip->close();

        $this->actingAs($this->admin)->post('/purchases/received-invoices/import', [
            'files' => [
                new UploadedFile($zipPath, 'fatture.zip', 'application/zip', null, true),
                UploadedFile::fake()->createWithContent('doppione.xml', $first),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, ReceivedInvoice::count());
        $this->assertSame(ReceivedInvoice::SOURCE_IMPORT, ReceivedInvoice::first()->source);
    }

    public function test_endpoints_are_normalized_for_the_price_list(): void
    {
        $this->assertSame('/invoices_download/{id}', OpenapiClient::endpoint('/invoices_download/9f1c2e44-aa31-4c1f-b0a7-1c2d3e4f5a6b'));
        $this->assertSame('/invoices', OpenapiClient::endpoint('/invoices'));
        $this->assertSame('/api_configurations/{id}', OpenapiClient::endpoint('/api_configurations/65a1b2c3d4'));
        $this->assertSame(['00200 – File non conforme'], ProcessSdiEvent::errors(['errore' => ['codice' => '00200', 'descrizione' => 'File non conforme']]));
    }
}
