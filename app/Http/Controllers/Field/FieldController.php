<?php

namespace App\Http\Controllers\Field;

use App\Actions\Catalog\ResolvePrice;
use App\Actions\Interventions\SaveIntervention;
use App\Actions\Interventions\SignIntervention;
use App\Enums\InterventionMode;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Interventions\SyncInterventionRequest;
use App\Models\Intervention;
use App\Models\InterventionCategory;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Interventi sul campo": a phone page that works offline. It carries the reference data it needs
 * (customers and sites, categories, products) and the service worker keeps it; interventions recorded
 * on the device are queued there and synced here, once each (client_uuid).
 */
class FieldController extends Controller
{
    public function index(Request $request, Settings $settings): Response
    {
        $this->authorize('create', Intervention::class);

        $locations = Location::query()->orderByDesc('is_primary')->orderBy('name')->get(['id', 'organization_id', 'name', 'address', 'city'])->groupBy('organization_id');

        return Inertia::render('field/Index', [
            'customers' => Organization::query()->where('role', OrganizationRole::Customer->value)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Organization $organization) => [
                    'value' => $organization->id,
                    'title' => $organization->name,
                    'locations' => ($locations[$organization->id] ?? collect())->map(fn (Location $location) => [
                        'value' => $location->id,
                        'title' => $location->name,
                        'subtitle' => trim(implode(', ', array_filter([$location->address, $location->city]))),
                    ])->values(),
                ]),
            'categories' => InterventionCategory::query()->where('is_active', true)->ordered()->get()
                ->map(fn (InterventionCategory $category) => [
                    'value' => $category->id,
                    'title' => $category->name,
                    'has_remote' => $category->remote_product_id !== null,
                    'has_onsite' => $category->onsite_product_id !== null,
                ]),
            'modes' => InterventionMode::options(),
            'products' => Product::query()->active()->orderBy('name')->limit(1500)->get(['id', 'code', 'name', 'unit', 'price', 'vat_rate_id'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'unit' => $product->unit->value,
                    'price' => (float) $product->price,
                    'vat_rate_id' => $product->vat_rate_id,
                ]),
            'rounding' => Intervention::rounding(),
            'userId' => $request->user()->id,
            'recent' => Intervention::query()->where('technician_id', $request->user()->id)->with('organization:id,name')->latest('id')->limit(8)->get()
                ->map(fn (Intervention $intervention) => [
                    'id' => $intervention->id,
                    'number' => $intervention->number,
                    'customer' => $intervention->organization?->name,
                    'started_at' => $intervention->started_at->toIso8601String(),
                    'duration_minutes' => $intervention->duration_minutes,
                    'status_label' => $intervention->status()->label(),
                    'status_color' => $intervention->status()->color(),
                ]),
            'cachedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * Creates an intervention recorded on the device; sending it again returns the one already created.
     */
    public function sync(SyncInterventionRequest $request, SaveIntervention $save, SignIntervention $sign, ResolvePrice $resolvePrice): JsonResponse
    {
        $data = $request->validated();

        if ($existing = Intervention::query()->where('client_uuid', $data['client_uuid'])->first()) {
            return $this->synced($existing, true);
        }

        $customer = Organization::query()->withTrashed()->findOrFail($data['organization_id']);
        $signature = $data['signature'] ?? null;
        unset($data['signature']);

        // Offline the customer's prices are unknown: materials without a price get the price that applies to the customer.
        $data['materials'] = array_map(function (array $material) use ($customer, $resolvePrice) {
            if (($material['unit_price'] ?? null) === null) {
                $product = isset($material['product_id']) ? Product::query()->find($material['product_id']) : null;
                $material['unit_price'] = $product ? $resolvePrice->handle($product, $customer)['price'] : 0;
                $material['vat_rate_id'] ??= $product?->vat_rate_id;
            }

            return $material;
        }, $data['materials'] ?? []);

        try {
            $intervention = DB::transaction(function () use ($save, $sign, $data, $signature, $request) {
                $intervention = $save->handle(new Intervention, [...$data, 'technician_id' => $request->user()->id]);

                if ($signature) {
                    $sign->handle($intervention, $signature['name'], $signature['data'], 'signature.data');
                }

                return $intervention;
            });
        } catch (UniqueConstraintViolationException) {
            // The same intervention synced twice at the same time.
            return $this->synced(Intervention::query()->where('client_uuid', $data['client_uuid'])->firstOrFail(), true);
        }

        return $this->synced($intervention, false);
    }

    private function synced(Intervention $intervention, bool $duplicate): JsonResponse
    {
        return response()->json([
            'id' => $intervention->id,
            'number' => $intervention->number,
            'url' => route('interventions.show', $intervention),
            'duplicate' => $duplicate,
        ], $duplicate ? 200 : 201);
    }
}
