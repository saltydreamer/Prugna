<?php

namespace Tests\Feature\Dolibarr;

use App\Enums\Role;
use App\Jobs\RunDolibarrImport;
use App\Models\DolibarrImport;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DolibarrImportPageTest extends TestCase
{
    use RefreshDatabase;

    private const BACKUP = __DIR__.'/../../Fixtures/dolibarr/backup.sql';

    public function test_the_page_requires_the_permission(): void
    {
        $this->actingAs(User::factory()->withRole(Role::User)->create())->get('/settings/dolibarr')->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/settings/dolibarr')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/DolibarrImport')->has('imports', 0));
    }

    public function test_an_uploaded_backup_is_simulated_in_the_background(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/settings/dolibarr', ['backup' => UploadedFile::fake()->create('notes.pdf', 10)])->assertSessionHasErrors('backup');

        $this->actingAs($admin)
            ->post('/settings/dolibarr', ['backup' => UploadedFile::fake()->createWithContent('dolibarr.sql', (string) file_get_contents(self::BACKUP))])
            ->assertRedirect('/settings/dolibarr');

        $import = DolibarrImport::query()->sole();
        $this->assertSame(DolibarrImport::SIMULATING, $import->status);
        Storage::disk('local')->assertExists($import->file_path);
        Queue::assertPushed(RunDolibarrImport::class, fn (RunDolibarrImport $job) => $job->importId === $import->id && $job->simulate);
    }

    public function test_simulation_then_import_and_the_backup_is_deleted(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        Storage::disk('local')->put('dolibarr/backup.sql', (string) file_get_contents(self::BACKUP));
        $import = DolibarrImport::query()->forceCreate(['user_id' => $admin->id, 'file_name' => 'backup.sql', 'file_path' => 'dolibarr/backup.sql', 'file_size' => 1, 'status' => DolibarrImport::SIMULATING]);

        RunDolibarrImport::dispatchSync($import->id, true);

        $import->refresh();
        $this->assertSame(DolibarrImport::SIMULATED, $import->status);
        $this->assertTrue($import->canImport());
        $this->assertSame(0, Invoice::count());

        // A second delivery of the same job does nothing.
        RunDolibarrImport::dispatchSync($import->id, true);

        $this->actingAs($admin)->post("/settings/dolibarr/{$import->id}/import")->assertRedirect();

        $import->refresh();
        $this->assertSame(DolibarrImport::IMPORTED, $import->status);
        $this->assertNull($import->file_path);
        $this->assertNotNull($import->imported_at);
        Storage::disk('local')->assertMissing('dolibarr/backup.sql');
        $this->assertSame(4, Invoice::count());
    }

    public function test_the_import_needs_a_simulation_without_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $import = DolibarrImport::query()->forceCreate(['user_id' => $admin->id, 'file_name' => 'backup.sql', 'file_path' => 'dolibarr/backup.sql', 'file_size' => 1, 'status' => DolibarrImport::SIMULATED, 'report' => ['errors' => [['message' => 'x', 'count' => 1, 'examples' => []]]]]);

        $this->actingAs($admin)->post("/settings/dolibarr/{$import->id}/import")->assertStatus(409);
    }

    public function test_a_file_that_is_not_a_dolibarr_backup_fails_with_a_message(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        Storage::disk('local')->put('dolibarr/other.sql', "CREATE TABLE `wp_posts` (\n  `ID` int NOT NULL\n);\nINSERT INTO `wp_posts` VALUES (1);\n");
        $import = DolibarrImport::query()->forceCreate(['user_id' => $admin->id, 'file_name' => 'other.sql', 'file_path' => 'dolibarr/other.sql', 'file_size' => 1, 'status' => DolibarrImport::SIMULATING]);

        RunDolibarrImport::dispatchSync($import->id, true);

        $import->refresh();
        $this->assertSame(DolibarrImport::FAILED, $import->status);
        $this->assertStringContainsString('non sembra un backup', $import->error);
    }
}
