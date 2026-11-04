<?php

namespace Tests\Feature\Pwa;

use App\Enums\Role;
use App\Models\CustomerPrice;
use App\Models\Intervention;
use App\Models\InterventionCategory;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FieldSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private User $technician;

    private InterventionCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->technician = User::factory()->withRole(Role::User)->create();
        $this->category = InterventionCategory::create([
            'name' => 'Assistenza',
            'remote_product_id' => Product::factory()->service()->create(['price' => 50])->id,
            'onsite_product_id' => Product::factory()->service()->create(['price' => 70])->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Organization $customer, array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'organization_id' => $customer->id,
            'category_id' => $this->category->id,
            'mode' => 'onsite',
            'started_at' => now()->subHours(3)->toIso8601String(),
            'duration_minutes' => 75,
            'description' => 'Sostituzione switch',
            'billable' => true,
            'callout' => true,
            'km' => 12,
            'travel_minutes' => 20,
            'materials' => [],
            ...$overrides,
        ];
    }

    public function test_the_field_page_carries_its_reference_data(): void
    {
        Organization::factory()->withDetails(2, 0)->create();

        $this->actingAs($this->technician)->get('/field')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('field/Index')
            ->has('customers', 1)
            ->has('customers.0.locations', 2)
            ->has('categories', 1)
            ->where('userId', $this->technician->id));
    }

    public function test_an_intervention_recorded_offline_is_synced_once(): void
    {
        Storage::fake('local');
        $customer = Organization::factory()->create();
        $cable = Product::factory()->create(['name' => 'Cavo', 'price' => 10]);
        CustomerPrice::create(['organization_id' => $customer->id, 'product_id' => $cable->id, 'price' => 8]);

        $payload = $this->payload($customer, [
            'materials' => [
                ['product_id' => $cable->id, 'description' => 'Cavo', 'unit' => 'pz', 'quantity' => 2, 'unit_price' => null],
                ['description' => 'Fascette', 'unit' => 'pz', 'quantity' => 10, 'unit_price' => 0.2],
            ],
            'signature' => ['name' => 'Mario Rossi', 'data' => self::PNG],
        ]);

        $this->actingAs($this->technician)->postJson('/field/sync', $payload)->assertCreated()->assertJsonPath('duplicate', false);
        // The phone sends it again (e.g. the answer was lost): nothing new is created.
        $this->actingAs($this->technician)->postJson('/field/sync', $payload)->assertOk()->assertJsonPath('duplicate', true);

        $intervention = Intervention::sole();
        $this->assertSame($payload['client_uuid'], $intervention->client_uuid);
        $this->assertSame($this->technician->id, $intervention->technician_id);
        $this->assertSame(75, $intervention->duration_minutes);
        $this->assertSame(['8.0000', '0.2000'], $intervention->materials->pluck('unit_price')->all(), 'The customer price applies to materials without a price.');
        $this->assertTrue($intervention->isSigned());
        $this->assertSame('Mario Rossi', $intervention->signed_by_name);
        Storage::disk('local')->assertExists($intervention->signature_path);
    }

    public function test_invalid_interventions_are_reported_to_the_phone(): void
    {
        $customer = Organization::factory()->create();

        $this->actingAs($this->technician)->postJson('/field/sync', $this->payload($customer, ['category_id' => 999, 'duration_minutes' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'duration_minutes']);

        $this->actingAs($this->technician)->postJson('/field/sync', $this->payload($customer, ['signature' => ['name' => 'X', 'data' => 'data:image/png;base64,bm90IGEgcG5n']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signature.data']);

        $this->assertSame(0, Intervention::count());
    }

    public function test_users_without_interventions_cannot_sync(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/field/sync', $this->payload(Organization::factory()->create()))->assertForbidden();
        $this->actingAs($user)->get('/field')->assertForbidden();
    }
}
