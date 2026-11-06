<?php

namespace Tests\Feature\Registry;

use App\Enums\LocationType;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\OpenapiCall;
use App\Models\Organization;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompanyRegistryDataTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        app(Settings::class)->set([
            'sdi.environment' => 'test',
            'sdi.username' => 'marco@example.com',
            'sdi.api_key' => 'api-key',
            'sdi.company_enabled' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(array $overrides = []): array
    {
        return [
            'taxCode' => '12485671007',
            'companyName' => 'OPENAPI S.P.A.',
            'vatCode' => '12485671007',
            'address' => ['registeredOffice' => [
                'toponym' => 'VIALE', 'street' => 'F TOMMASO MARINETTI', 'streetNumber' => '221',
                'streetName' => 'VIALE DELL\'INDUSTRIA DELLA ROMA 221', 'town' => 'ROMA', 'province' => 'RM', 'zipCode' => '00143',
            ]],
            'activityStatus' => 'ATTIVA',
            'reaCode' => '1378273',
            'cciaa' => 'RM',
            'atecoClassification' => ['ateco' => ['code' => '621', 'description' => 'Attività di programmazione informatica']],
            'detailedLegalForm' => ['code' => 'SP', 'description' => 'SOCIETÀ PER AZIONI'],
            'endDate' => null,
            'pec' => 'OPENAPI@LEGALMAIL.IT',
            'sdiCode' => 'usal8pv',
            'lastUpdateTimestamp' => 1708705000,
            ...$overrides,
        ];
    }

    private function fakeOpenapi(array $record, int $status = 200): void
    {
        Http::fake([
            'test.oauth.openapi.it/scopes*' => Http::response(['data' => ['POST:test.sdi.openapi.it/invoices', 'GET:test.company.openapi.com/IT-advanced', 'GET:test.company.openapi.com/IT-start']]),
            'test.oauth.openapi.it/token' => Http::response(['token' => 'company-token', 'success' => true]),
            'test.company.openapi.com/*' => Http::response($status === 200 ? ['data' => [$record], 'success' => true] : ['success' => false, 'message' => 'no company matches this id: ', 'data' => null], $status),
        ]);
    }

    public function test_a_new_customer_is_filled_from_the_registry(): void
    {
        $this->fakeOpenapi($this->record());

        $this->actingAs($this->admin)->getJson('/registry/company-lookup?role=customer&code=IT 12485671007')
            ->assertOk()
            ->assertJsonPath('company.name', 'OPENAPI S.P.A.')
            ->assertJsonPath('company.sdi_code', 'USAL8PV')
            ->assertJsonPath('company.pec', 'openapi@legalmail.it')
            ->assertJsonPath('company.location.address', "Viale dell'Industria della Roma 221")
            ->assertJsonPath('company.location.city', 'Roma')
            ->assertJsonPath('company.location.full', "Viale dell'Industria della Roma 221, 00143 Roma (RM)")
            ->assertJsonPath('company.legal_form', 'Società per azioni')
            ->assertJsonPath('company.rea', 'RM-1378273')
            ->assertJsonPath('company.ceased', false)
            ->assertJsonPath('existing', null);

        // Only the Company scopes, with a token of its own; the call is in the cost log.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/token') && $request['scopes'] === ['GET:test.company.openapi.com/IT-advanced', 'GET:test.company.openapi.com/IT-start']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://test.company.openapi.com/IT-advanced/12485671007' && $request->hasHeader('Authorization', 'Bearer company-token'));
        $this->assertSame('company-token', app(Settings::class)->get('sdi.company_token'));
        $this->assertNull(app(Settings::class)->get('sdi.token'));
        $this->assertSame('GET /IT-advanced/{id}', OpenapiCall::sole()->endpoint);

        $existing = Organization::factory()->create(['vat_number' => '12485671007', 'role' => OrganizationRole::Supplier]);
        $this->actingAs($this->admin)->getJson('/registry/company-lookup?role=customer&code=12485671007')->assertJsonPath('existing.id', $existing->id);

        $this->actingAs($this->admin)->getJson('/registry/company-lookup?role=customer&code=DE123456789')->assertJsonValidationErrors('code');
    }

    public function test_an_organization_is_updated_with_the_chosen_registry_data(): void
    {
        $this->fakeOpenapi($this->record());
        $organization = Organization::factory()->create(['name' => 'Openapi S.p.A.', 'vat_number' => '12485671007', 'tax_code' => null, 'sdi_code' => 'OLD0000', 'pec' => null]);
        $location = $organization->locations()->create(['name' => 'Sede legale', 'type' => LocationType::Registered, 'address' => 'Via Vecchia 1', 'postal_code' => '00100', 'city' => 'Roma', 'province' => 'RM', 'country' => 'IT']);

        $response = $this->actingAs($this->admin)->getJson("/registry/organizations/{$organization->id}/company-data")->assertOk();

        // Same name except for case and punctuation: nothing to change.
        $this->assertSame(['tax_code', 'sdi_code', 'pec', 'location'], array_column($response->json('changes'), 'field'));
        $this->assertSame('OLD0000', $response->json('changes.1.current'));

        $this->actingAs($this->admin)->put("/registry/organizations/{$organization->id}/company-data", ['fields' => ['pec', 'location']])->assertRedirect();

        $organization->refresh();
        $this->assertSame('openapi@legalmail.it', $organization->pec);
        $this->assertSame('OLD0000', $organization->sdi_code);
        $this->assertSame("Viale dell'Industria della Roma 221", $location->fresh()->address);
        $this->assertSame('00143', $location->fresh()->postal_code);
        // The data read is reused: one paid call.
        $this->assertSame(1, OpenapiCall::query()->count());
    }

    public function test_a_registered_office_is_added_when_missing_and_public_bodies_keep_their_office_code(): void
    {
        $this->fakeOpenapi($this->record(['activityStatus' => 'CESSATA', 'endDate' => '2025-06-30']));
        $organization = Organization::factory()->create(['vat_number' => '12485671007', 'type' => OrganizationType::PublicBody, 'sdi_code' => 'UFE3RT']);
        $organization->locations()->create(['name' => 'Magazzino', 'type' => LocationType::Warehouse, 'address' => 'Via del Porto 3', 'city' => 'Ostia', 'country' => 'IT']);

        $response = $this->actingAs($this->admin)->getJson("/registry/organizations/{$organization->id}/company-data")
            ->assertOk()
            ->assertJsonPath('company.ceased', true)
            ->assertJsonPath('company.ceased_on', '2025-06-30');
        $this->assertNotContains('sdi_code', array_column($response->json('changes'), 'field'));

        $this->actingAs($this->admin)->put("/registry/organizations/{$organization->id}/company-data", ['fields' => ['location']])->assertRedirect();

        $office = $organization->locations()->where('type', LocationType::Registered->value)->sole();
        $this->assertSame('Sede legale', $office->name);
        $this->assertSame('Roma', $office->city);
        $this->assertSame(2, $organization->locations()->count());
    }

    public function test_errors_are_explained(): void
    {
        $this->fakeOpenapi([], 404);
        $organization = Organization::factory()->create(['vat_number' => '12485671007']);

        $this->actingAs($this->admin)->getJson("/registry/organizations/{$organization->id}/company-data")
            ->assertStatus(422)
            ->assertJsonPath('message', "Nel Registro Imprese non c'è nessuna azienda con partita IVA o codice fiscale 12485671007.");

        app(Settings::class)->set(['sdi.company_enabled' => false]);
        $this->actingAs($this->admin)->get("/registry/organizations/{$organization->id}")->assertInertia(fn ($page) => $page->where('companyLookup', false));
        $this->actingAs($this->admin)->getJson("/registry/organizations/{$organization->id}/company-data")->assertStatus(422);

        $user = User::factory()->create();
        $this->actingAs($user)->getJson("/registry/organizations/{$organization->id}/company-data")->assertForbidden();
    }

    public function test_the_company_service_must_be_active_on_the_account(): void
    {
        Http::fake(['test.oauth.openapi.it/scopes*' => Http::response(['data' => ['POST:test.sdi.openapi.it/invoices']])]);
        $organization = Organization::factory()->create(['vat_number' => '12485671007']);

        $this->actingAs($this->admin)->getJson("/registry/organizations/{$organization->id}/company-data")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, '«Company»'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'company.openapi.com'));
    }
}
