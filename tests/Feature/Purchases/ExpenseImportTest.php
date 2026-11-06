<?php

namespace Tests\Feature\Purchases;

use App\Actions\Expenses\ImportExpenseDocument;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Expense;
use App\Models\ExpenseImport;
use App\Models\ExpenseImportItem;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ExpenseImportNotification;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpenseImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-06 10:00:00');
        Storage::fake('local');
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
        app(Settings::class)->set(['ai.enabled' => true, 'ai.api_key' => 'sk-or-test', 'ai.model' => 'anthropic/claude-test', 'ai.privacy' => 'no_training']);
    }

    /**
     * An expense read by the AI.
     *
     * @param  array<string, mixed>  $supplier
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reading(array $supplier, array $overrides = []): array
    {
        return [
            'document_type' => 'receipt',
            'supplier' => [
                'name' => null, 'vat_number' => null, 'tax_code' => null, 'address' => null, 'postal_code' => null, 'city' => null,
                'province' => null, 'country' => 'IT', 'email' => null, 'pec' => null, 'phone' => null, ...$supplier,
            ],
            'number' => '0042',
            'date' => '2026-10-30',
            'due_date' => null,
            'payment_method' => 'card',
            'description' => 'Pranzo di lavoro',
            'lines' => [['description' => 'Pranzo', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => 22, 'total' => 100]],
            'total_taxable' => 100,
            'total_vat' => 22,
            'total' => 122,
            'warnings' => [],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>|Response|int  ...$results  One per reading, in order: data, or an HTTP status for a failure.
     */
    private function fakeOpenRouter(array|int ...$results): void
    {
        $sequence = Http::sequence();

        foreach ($results as $data) {
            is_int($data)
                ? $sequence->push(['error' => ['message' => 'Provider down']], $data)
                : $sequence->push([
                    'model' => 'anthropic/claude-test',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($data)]]],
                    'usage' => ['prompt_tokens' => 1800, 'completion_tokens' => 400, 'cost' => 0.0123],
                ]);
        }

        Http::fake([
            'openrouter.ai/api/v1/models*' => Http::response(['data' => [[
                'id' => 'anthropic/claude-test', 'name' => 'Claude test', 'context_length' => 200000,
                'architecture' => ['input_modalities' => ['text', 'image', 'file']],
                'supported_parameters' => ['response_format', 'structured_outputs'],
                'pricing' => ['prompt' => '0.000004', 'completion' => '0.00002'],
            ]]]),
            'openrouter.ai/api/v1/chat/completions' => $sequence,
        ]);
    }

    /**
     * Uploads the files one by one and starts the reading (the queue runs synchronously in tests).
     *
     * @param  list<UploadedFile>  $files
     */
    private function import(array $files, ?User $user = null): ExpenseImport
    {
        $user ??= $this->admin;
        $id = $this->actingAs($user)->postJson('/purchases/expenses/imports')->assertCreated()->json('id');

        foreach ($files as $file) {
            $this->actingAs($user)->post("/purchases/expenses/imports/{$id}/files", ['file' => $file], ['Accept' => 'application/json'])->assertCreated();
        }

        $this->actingAs($user)->post("/purchases/expenses/imports/{$id}/start")->assertRedirect("/purchases/expenses/imports/{$id}");

        return ExpenseImport::query()->findOrFail($id);
    }

    public function test_every_document_becomes_an_expense_creating_new_suppliers_or_using_the_generic_one(): void
    {
        $this->fakeOpenRouter(
            $this->reading(['name' => 'Bar Centrale', 'vat_number' => 'IT00743110157', 'address' => 'Via Roma 3', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI', 'email' => 'Info@BarCentrale.example']),
            $this->reading([], ['number' => '17']),
            $this->reading(['name' => 'Bar Centrale', 'vat_number' => '00743110157'], ['number' => '0043']),
            $this->reading([], ['number' => '18', 'date' => null]),
        );

        $import = $this->import([
            UploadedFile::fake()->image('scontrino-1.jpg'), UploadedFile::fake()->image('scontrino-2.jpg'),
            UploadedFile::fake()->image('scontrino-3.jpg'), UploadedFile::fake()->image('scontrino-4.jpg'),
        ]);

        $items = $import->items()->orderBy('id')->get();
        $this->assertSame(array_fill(0, 4, ExpenseImportItem::RECORDED), $items->pluck('status')->all());
        $this->assertSame([ExpenseImportItem::SUPPLIER_CREATED, ExpenseImportItem::SUPPLIER_GENERIC, ExpenseImportItem::SUPPLIER_MATCHED, ExpenseImportItem::SUPPLIER_GENERIC], $items->pluck('supplier_source')->all());

        // The new supplier comes from the document; the next receipt of it finds it.
        $supplier = Organization::query()->where('name', 'Bar Centrale')->sole();
        $this->assertTrue($supplier->isSupplier());
        $this->assertSame('00743110157', $supplier->vat_number);
        $this->assertSame('info@barcentrale.example', $supplier->email);
        $this->assertSame('Milano', $supplier->locations()->sole()->city);
        $this->assertSame($supplier->id, $items[2]->supplier_id);

        // One generic supplier for every unreadable one, remembered in the settings.
        $generic = Organization::query()->findOrFail(app(Settings::class)->get(ImportExpenseDocument::GENERIC_SUPPLIER));
        $this->assertSame('Fornitore generico', $generic->name);
        $this->assertSame([$generic->id, $generic->id], [$items[1]->supplier_id, $items[3]->supplier_id]);
        $this->assertContains('Data del documento non letta: è stata usata quella di oggi.', $items[3]->warnings);

        $expense = $items[0]->expense;
        $this->assertSame('122.00', $expense->total);
        $this->assertSame($this->admin->id, $expense->owner_id);
        $this->assertSame('2026-10-30', $expense->paid_at->toDateString());
        $this->assertNotNull($expense->attachment_path);
        $this->assertSame(4, Expense::query()->count());

        $this->assertNotNull($import->fresh()->completed_at);
        Notification::assertSentToTimes($this->admin, ExpenseImportNotification::class, 1);
        Notification::assertSentTo($this->admin, ExpenseImportNotification::class, fn (ExpenseImportNotification $notification) => $notification->message() === '4 spese registrate.');

        $this->actingAs($this->admin)->get("/purchases/expenses/imports/{$import->id}")->assertInertia(fn (Assert $page) => $page
            ->component('purchases/expenses/Import')
            ->where('batch.counts.recorded', 4)
            ->where('batch.total', 488)
            ->has('items', 4));
        $this->actingAs($this->admin)->get('/purchases/expenses')->assertInertia(fn (Assert $page) => $page->where('imports.0.recorded', 4));
    }

    public function test_recorded_documents_are_skipped_and_failed_ones_can_be_retried_or_completed_by_hand(): void
    {
        $supplier = Organization::factory()->supplier()->create(['vat_number' => '00743110157']);
        $existing = Expense::factory()->create(['supplier_id' => $supplier->id, 'number' => '0042', 'document_date' => '2026-10-30']);

        $this->fakeOpenRouter(
            $this->reading(['name' => 'Bar Centrale', 'vat_number' => '00743110157']),
            500,
            $this->reading(['name' => 'Bar Centrale', 'vat_number' => '00743110157'], ['lines' => [], 'total_taxable' => null, 'total' => null, 'number' => '99']),
            $this->reading(['name' => 'Bar Centrale', 'vat_number' => '00743110157'], ['number' => '0050']),
        );

        $import = $this->import([UploadedFile::fake()->image('doppio.jpg'), UploadedFile::fake()->image('rotto.jpg'), UploadedFile::fake()->image('vuoto.jpg')]);
        [$duplicate, $broken, $empty] = $import->items()->orderBy('id')->get()->all();

        $this->assertSame(ExpenseImportItem::DUPLICATE, $duplicate->status);
        $this->assertSame($existing->id, $duplicate->expense_id);
        $this->assertSame(ExpenseImportItem::FAILED, $broken->status);
        $this->assertNotEmpty($broken->error);
        $this->assertSame(ExpenseImportItem::FAILED, $empty->status);
        $this->assertSame('Importi o aliquote IVA non leggibili: completa la spesa a mano.', $empty->error);
        $this->assertSame(1, Expense::query()->count());

        // The AI was down: read again.
        $this->actingAs($this->admin)->post("/purchases/expenses/imports/{$import->id}/items/{$broken->id}/retry")->assertRedirect();
        $this->assertSame(ExpenseImportItem::RECORDED, $broken->fresh()->status);
        Notification::assertSentToTimes($this->admin, ExpenseImportNotification::class, 2);

        // Nothing to record: completed in the usual form from its reading.
        $this->actingAs($this->admin)->post('/purchases/expenses', [
            'type' => 'receipt', 'supplier_id' => $supplier->id, 'number' => '99', 'document_date' => '2026-10-30', 'ai_extraction_id' => $empty->ai_extraction_id,
            'lines' => [['type' => 'item', 'description' => 'Caffè', 'quantity' => 1, 'unit_price' => 10, 'vat_rate_id' => $existing->lines()->value('vat_rate_id')]],
        ])->assertRedirect();
        $this->assertSame(ExpenseImportItem::RECORDED, $empty->fresh()->status);
        $this->assertNotNull($empty->fresh()->expense_id);
    }

    public function test_photos_need_the_ai_permission_and_an_import_belongs_to_whoever_uploaded_it(): void
    {
        Http::fake();
        $clerk = User::factory()->create();
        $clerk->givePermissionTo(Permission::ExpensesManage->value, Permission::ExpensesView->value);
        $id = $this->actingAs($clerk)->postJson('/purchases/expenses/imports')->assertCreated()->json('id');

        $this->actingAs($clerk)->post("/purchases/expenses/imports/{$id}/files", ['file' => UploadedFile::fake()->image('scontrino.jpg')], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->actingAs($this->admin)->get("/purchases/expenses/imports/{$id}")->assertForbidden();
        $this->actingAs(User::factory()->withRole(Role::User)->create())->postJson('/purchases/expenses/imports')->assertForbidden();

        // Started: no more files, and starting again does nothing.
        $this->actingAs($clerk)->post("/purchases/expenses/imports/{$id}/start")->assertRedirect();
        $this->actingAs($clerk)->post("/purchases/expenses/imports/{$id}/files", ['file' => UploadedFile::fake()->image('altro.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(409);
        $this->assertNotNull(ExpenseImport::query()->find($id)->completed_at);
    }
}
