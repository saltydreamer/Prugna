<?php

namespace App\Http\Controllers\Interventions;

use App\Actions\Interventions\SaveIntervention;
use App\Enums\InterventionMode;
use App\Enums\InterventionStatus;
use App\Enums\OrganizationRole;
use App\Enums\Permission;
use App\Enums\UnitOfMeasure;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sales\ProvidesDocumentEditorOptions;
use App\Http\Requests\Interventions\InterventionRequest;
use App\Http\Resources\InterventionResource;
use App\Http\Resources\VatRateResource;
use App\Models\Intervention;
use App\Models\InterventionCategory;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Models\VatRate;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Interventi: remote and on-site work for customers, with a timer and billing.
 */
class InterventionController extends Controller
{
    use ProvidesDocumentEditorOptions;

    private const SORTABLE = ['started_at', 'duration_minutes', 'number'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Intervention::class);

        $user = $request->user();
        $supervisor = $user->can(Permission::InterventionsSupervise->value);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', Rule::in(['mine', 'all'])],
            'technician' => ['nullable', 'integer'],
            'customer' => ['nullable', 'integer'],
            'order' => ['nullable', 'integer'],
            'category' => ['nullable', 'integer'],
            'mode' => ['nullable', Rule::enum(InterventionMode::class)],
            'status' => ['nullable', Rule::enum(InterventionStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort_by' => ['nullable', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        $scope = $supervisor ? ($filters['scope'] ?? 'all') : 'mine';

        $base = Intervention::query()
            ->visibleTo($user)
            ->when($scope === 'mine', fn (Builder $query) => $query->where('technician_id', $user->id))
            ->when($supervisor && ($filters['technician'] ?? null), fn (Builder $query) => $query->where('technician_id', $filters['technician']))
            ->when($filters['customer'] ?? null, fn (Builder $query, int $id) => $query->where('organization_id', $id))
            ->when($filters['order'] ?? null, fn (Builder $query, int $id) => $query->where('order_id', $id))
            ->when($filters['category'] ?? null, fn (Builder $query, int $id) => $query->where('category_id', $id))
            ->when($filters['mode'] ?? null, fn (Builder $query, string $mode) => $query->where('mode', $mode))
            // Dates are local days, times are stored in UTC.
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->where('started_at', '>=', Carbon::parse($date, config('app.display_timezone'))->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->where('started_at', '<=', Carbon::parse($date, config('app.display_timezone'))->endOfDay()->utc()))
            ->search($filters['search'] ?? null);

        $listed = (clone $base)->withStatus($filters['status'] ?? null);

        $interventions = (clone $listed)
            ->with(['organization', 'category', 'technician:id,name', 'order:id,number,title', 'invoiceLines.invoice'])
            ->orderBy($filters['sort_by'] ?? 'started_at', $filters['sort_dir'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        $completed = (clone $listed)->whereNotNull('duration_minutes')->get(['id', 'duration_minutes', 'travel_minutes', 'mode']);
        $toInvoice = (clone $base)->toInvoice()->get(['id', 'duration_minutes', 'organization_id']);

        return Inertia::render('interventions/Index', [
            'interventions' => InterventionResource::collection($interventions),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'scope' => $scope,
                'technician' => isset($filters['technician']) ? (int) $filters['technician'] : null,
                'customer' => isset($filters['customer']) ? (int) $filters['customer'] : null,
                'order' => isset($filters['order']) ? (int) $filters['order'] : null,
                'category' => isset($filters['category']) ? (int) $filters['category'] : null,
                'mode' => $filters['mode'] ?? null,
                'status' => $filters['status'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'sort_by' => $filters['sort_by'] ?? null,
                'sort_dir' => $filters['sort_dir'] ?? null,
                'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'summary' => [
                'count' => $completed->count(),
                'minutes' => (int) $completed->sum('duration_minutes'),
                'billable_minutes' => (int) $completed->sum(fn (Intervention $intervention) => $intervention->billableMinutes()),
                'to_invoice_count' => $toInvoice->count(),
                'to_invoice_minutes' => (int) $toInvoice->sum(fn (Intervention $intervention) => $intervention->billableMinutes()),
                'to_invoice_customers' => $toInvoice->pluck('organization_id')->unique()->count(),
            ],
            'customers' => $this->organizationOptions(OrganizationRole::Customer),
            'categories' => $this->categoryOptions(),
            'technicians' => $supervisor ? $this->technicianOptions() : null,
            'modes' => InterventionMode::options(),
            'statuses' => InterventionStatus::options(),
            'rounding' => Intervention::rounding(),
        ]);
    }

    /**
     * New intervention, optionally for a customer (?organization=) or an order (?order=).
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', Intervention::class);

        $order = $request->integer('order') ? Order::query()->find($request->integer('order')) : null;
        $organizationId = $order?->organization_id ?? ($request->integer('organization') ?: null);
        $category = InterventionCategory::query()->where('is_active', true)->ordered()->first();

        return Inertia::render('interventions/Edit', [
            'intervention' => null,
            'defaults' => [
                'organization_id' => $organizationId,
                'order_id' => $order?->id,
                'category_id' => $category?->id,
                'mode' => InterventionMode::Remote->value,
                'technician_id' => $request->user()->id,
                'started_at' => now()->subHour()->startOfHour()->toIso8601String(),
                'duration_minutes' => 60,
                'billable' => true,
                'callout' => false,
                'km' => 0,
                'travel_minutes' => 0,
                'materials' => [],
            ],
            ...$this->formOptions($request->user()),
        ]);
    }

    public function store(InterventionRequest $request, SaveIntervention $save): RedirectResponse
    {
        $intervention = $save->handle(new Intervention, [
            'technician_id' => $request->user()->id,
            ...$request->validated(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Intervento :number registrato.', ['number' => $intervention->number])]);

        return to_route('interventions.show', $intervention);
    }

    public function show(Intervention $intervention): Response
    {
        $this->authorize('view', $intervention);

        $intervention->load(['organization', 'location', 'contact', 'order', 'category', 'technician', 'materials', 'invoiceLines.invoice']);

        return Inertia::render('interventions/Show', [
            'intervention' => InterventionResource::make($intervention)->resolve(),
            'rounding' => Intervention::rounding(),
        ]);
    }

    public function edit(Request $request, Intervention $intervention): Response
    {
        $this->authorize('update', $intervention);

        $intervention->load(['materials', 'order']);

        return Inertia::render('interventions/Edit', [
            'intervention' => InterventionResource::make($intervention)->resolve(),
            'defaults' => null,
            ...$this->formOptions($request->user(), $intervention),
        ]);
    }

    public function update(InterventionRequest $request, Intervention $intervention, SaveIntervention $save): RedirectResponse
    {
        $save->handle($intervention, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Intervento salvato.')]);

        return to_route('interventions.show', $intervention);
    }

    public function destroy(Intervention $intervention): RedirectResponse
    {
        $this->authorize('delete', $intervention);

        $intervention->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Intervento eliminato.')]);

        return to_route('interventions.index');
    }

    /**
     * Starts the timer: the intervention begins now and has no duration until "Termina".
     */
    public function start(Request $request, SaveIntervention $save): RedirectResponse
    {
        $this->authorize('create', Intervention::class);

        $data = $request->validate([
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')->where('role', 'customer')->whereNull('deleted_at')],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('organization_id', $request->input('organization_id'))],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->where('organization_id', $request->input('organization_id'))],
            'category_id' => ['required', 'integer', Rule::exists('intervention_categories', 'id')->where('is_active', true)],
            'mode' => ['required', Rule::enum(InterventionMode::class)],
            'description' => ['nullable', 'string', 'max:10000'],
        ], [], ['organization_id' => 'cliente', 'category_id' => 'categoria', 'mode' => 'modalità']);

        $running = $this->runningFor($request->user());

        if ($running) {
            throw ValidationException::withMessages(['organization_id' => __('Hai già un intervento in corso (:number): terminalo prima di avviarne un altro.', ['number' => $running->number])]);
        }

        $intervention = $save->handle(new Intervention, [
            ...$data,
            'technician_id' => $request->user()->id,
            'started_at' => now(),
            'duration_minutes' => null,
            'billable' => true,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Intervento :number avviato.', ['number' => $intervention->number])]);

        return to_route('interventions.show', $intervention);
    }

    /**
     * Stops the timer: the duration is the time elapsed (at least one minute).
     */
    public function stop(Intervention $intervention): RedirectResponse
    {
        $this->authorize('update', $intervention);

        abort_unless($intervention->isRunning(), 409, __("L'intervento è già terminato."));

        $intervention->forceFill(['duration_minutes' => min(1440, max(1, (int) round($intervention->started_at->diffInSeconds(now()) / 60)))])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Intervento terminato: descrivi il lavoro svolto e salva.')]);

        return to_route('interventions.edit', $intervention);
    }

    /**
     * Locations, contacts and open orders of a customer (intervention form and timer).
     */
    public function customer(Organization $organization): JsonResponse
    {
        $this->authorize('create', Intervention::class);

        abort_unless($organization->role === OrganizationRole::Customer, 404);

        return response()->json([
            'locations' => $organization->locations()->orderByDesc('is_primary')->orderBy('name')->get()
                ->map(fn ($location) => ['value' => $location->id, 'title' => $location->name, 'subtitle' => $location->full_address]),
            'contacts' => $organization->contacts()->orderByDesc('is_primary')->orderBy('last_name')->get()
                ->map(fn ($contact) => ['value' => $contact->id, 'title' => $contact->full_name, 'subtitle' => $contact->email]),
            'orders' => $organization->orders()->whereIn('status', ['open', 'in_progress'])->orderByDesc('id')->limit(100)->get(['id', 'number', 'title'])
                ->map(fn (Order $order) => ['value' => $order->id, 'title' => $order->number, 'subtitle' => $order->title]),
        ]);
    }

    /**
     * Customers and categories for the "Avvia intervento" dialog.
     */
    public function startOptions(): JsonResponse
    {
        $this->authorize('create', Intervention::class);

        return response()->json([
            'customers' => $this->organizationOptions(OrganizationRole::Customer),
            'categories' => $this->categoryOptions(),
            'modes' => InterventionMode::options(),
        ]);
    }

    /**
     * The running intervention of the user, shared with every page (timer in the top bar).
     */
    public static function runningFor(?User $user): ?Intervention
    {
        if (! $user?->can(Permission::InterventionsManage->value)) {
            return null;
        }

        return Intervention::query()->with('organization:id,name')->where('technician_id', $user->id)->whereNull('duration_minutes')->latest('started_at')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(User $user, ?Intervention $intervention = null): array
    {
        $settings = app(Settings::class);

        return [
            'customers' => $this->organizationOptions(OrganizationRole::Customer, $intervention?->organization_id),
            'categories' => $this->categoryOptions($intervention?->category_id),
            'technicians' => $user->can(Permission::InterventionsSupervise->value) ? $this->technicianOptions() : null,
            'modes' => InterventionMode::options(),
            'vatRates' => VatRateResource::collection(VatRate::query()->where('is_active', true)->ordered()->get())->resolve(),
            'units' => UnitOfMeasure::options(),
            'rounding' => Intervention::rounding(),
            'extras' => collect(['callout', 'km', 'travel'])->mapWithKeys(fn (string $extra) => [
                $extra => ($id = $settings->get("interventions.{$extra}_product_id")) ? Product::query()->find($id)?->only(['id', 'name']) : null,
            ]),
        ];
    }

    /**
     * @return list<array{value: int, title: string, has_remote: bool, has_onsite: bool}>
     */
    private function categoryOptions(?int $currentId = null): array
    {
        return InterventionCategory::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->when($currentId, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
            ->ordered()
            ->get()
            ->map(fn (InterventionCategory $category) => [
                'value' => $category->id,
                'title' => $category->name,
                'has_remote' => $category->remote_product_id !== null,
                'has_onsite' => $category->onsite_product_id !== null,
            ])
            ->all();
    }

    /**
     * @return list<array{value: int, title: string}>
     */
    private function technicianOptions(): array
    {
        return User::query()->active()->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => $user->id, 'title' => $user->name])
            ->all();
    }
}
