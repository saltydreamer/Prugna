<?php

namespace App\Http\Controllers\Interventions;

use App\Actions\Invoices\RegimeVatRate;
use App\Actions\Invoices\SaveInvoice;
use App\Enums\InterventionMode;
use App\Enums\InvoiceType;
use App\Enums\OrganizationRole;
use App\Enums\Permission;
use App\Enums\QuoteLineType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sales\ProvidesDocumentEditorOptions;
use App\Http\Resources\InterventionResource;
use App\Http\Resources\ServiceContractResource;
use App\Http\Resources\VatRateResource;
use App\Models\InterventionCategory;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ServiceContract;
use App\Models\User;
use App\Models\VatRate;
use App\Support\DocumentNumber;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contratti a monte ore: hours bought by a customer, used up by the covered interventions.
 */
class ServiceContractController extends Controller
{
    use ProvidesDocumentEditorOptions;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ServiceContract::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'customer' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['all', 'active', 'closed'])],
        ]);

        $status = $filters['status'] ?? 'active';

        $contracts = ServiceContract::query()
            ->with(['organization', 'invoice'])
            ->search($filters['search'] ?? null)
            ->when($filters['customer'] ?? null, fn (Builder $query, int $id) => $query->where('organization_id', $id))
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'closed', fn (Builder $query) => $query->where('is_active', false))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('interventions/contracts/Index', [
            'contracts' => ServiceContractResource::collection($contracts),
            'filters' => ['search' => $filters['search'] ?? '', 'customer' => isset($filters['customer']) ? (int) $filters['customer'] : null, 'status' => $status],
            'customers' => $this->organizationOptions(OrganizationRole::Customer),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', ServiceContract::class);

        return Inertia::render('interventions/contracts/Edit', [
            'contract' => null,
            'defaults' => [
                'organization_id' => $request->integer('organization') ?: null,
                'title' => __('Pacchetto ore di assistenza'),
                'hours' => 10,
                'starts_on' => today()->toDateString(),
                'ends_on' => today()->addYear()->subDay()->toDateString(),
                'mode' => null,
                'category_ids' => [],
                'price' => 0,
                'vat_rate_id' => VatRate::default()?->id,
                'manager_id' => $request->user()->id,
                'is_active' => true,
            ],
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ServiceContract::class);

        $data = $this->validated($request);

        $contract = DB::transaction(function () use ($data) {
            $contract = new ServiceContract($data);
            $contract->forceFill(DocumentNumber::next(ServiceContract::class, 'MO', (int) $contract->starts_on->format('Y')))->save();

            return $contract;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contratto :number creato.', ['number' => $contract->number])]);

        return to_route('interventions.contracts.show', $contract);
    }

    public function show(ServiceContract $contract): Response
    {
        $this->authorize('view', $contract);

        $contract->load(['organization', 'manager', 'invoice', 'quote']);
        $categories = InterventionCategory::query()->whereIn('id', $contract->category_ids ?? [])->pluck('name');

        return Inertia::render('interventions/contracts/Show', [
            'contract' => ServiceContractResource::make($contract)->resolve(),
            'categories' => $categories->isEmpty() ? [__('Tutte le categorie')] : $categories->values(),
            'interventions' => InterventionResource::collection(
                $contract->interventions()->with(['category', 'technician:id,name', 'organization'])->orderByDesc('started_at')->get()
            )->resolve(),
        ]);
    }

    public function edit(ServiceContract $contract): Response
    {
        $this->authorize('update', $contract);

        return Inertia::render('interventions/contracts/Edit', [
            'contract' => ServiceContractResource::make($contract)->resolve(),
            'defaults' => null,
            ...$this->formOptions($contract),
        ]);
    }

    public function update(Request $request, ServiceContract $contract): RedirectResponse
    {
        $this->authorize('update', $contract);

        $data = $this->validated($request, $contract);
        $contract->fill($data);

        // More hours: the alerts may fire again.
        if ($contract->isDirty('hours')) {
            $contract->forceFill(['alerted_threshold_at' => null, 'alerted_exhausted_at' => null]);
        }

        $contract->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contratto salvato.')]);

        return to_route('interventions.contracts.show', $contract);
    }

    public function destroy(ServiceContract $contract): RedirectResponse
    {
        $this->authorize('delete', $contract);

        $contract->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contratto eliminato.')]);

        return to_route('interventions.contracts.index');
    }

    /**
     * Draft invoice of the package (once).
     */
    public function invoice(Request $request, ServiceContract $contract, SaveInvoice $saveInvoice, RegimeVatRate $vatRates, Settings $settings): RedirectResponse
    {
        $this->authorize('update', $contract);
        $this->authorize('create', Invoice::class);

        abort_if($contract->invoice_id !== null, 409, __('Il pacchetto è già in fattura.'));
        abort_if($contract->quote_id !== null, 409, __('Il pacchetto è nel preventivo: si fattura con il preventivo o con il suo ordine.'));
        abort_if((float) $contract->price <= 0, 409, __('Indica il prezzo del pacchetto prima di fatturarlo.'));

        $customer = Organization::query()->withTrashed()->findOrFail($contract->organization_id);
        $product = $contract->product_id ? Product::query()->find($contract->product_id) : null;
        $vatRateId = $contract->vat_rate_id ?? $product?->vat_rate_id ?? VatRate::default()?->id;
        $period = $contract->ends_on
            ? __('dal :from al :to', ['from' => $contract->starts_on->format('d/m/Y'), 'to' => $contract->ends_on->format('d/m/Y')])
            : __('dal :from', ['from' => $contract->starts_on->format('d/m/Y')]);

        $invoice = DB::transaction(function () use ($saveInvoice, $vatRates, $contract, $customer, $product, $vatRateId, $period, $request, $settings) {
            $invoice = $saveInvoice->handle(new Invoice, [
                'type' => InvoiceType::Invoice,
                'organization_id' => $customer->id,
                'payment_term_id' => $customer->payment_term_id,
                'title' => $contract->title,
                'issue_date' => today()->toDateString(),
                'owner_id' => $request->user()->id,
                'notes' => $settings->get('fiscal.invoice_notes'),
                'lines' => [[
                    'type' => QuoteLineType::Item,
                    'product_id' => $product?->id,
                    'code' => $product?->code,
                    'description' => __(':title – :hours ore (:number), validità :period', [
                        'title' => $contract->title,
                        'hours' => rtrim(rtrim(number_format((float) $contract->hours, 2, ',', '.'), '0'), ','),
                        'number' => $contract->number,
                        'period' => $period,
                    ]),
                    'unit' => null,
                    'quantity' => 1,
                    'unit_price' => (float) $contract->price,
                    'discount_percent' => 0,
                    'vat_rate_id' => $vatRates->for($vatRateId),
                ]],
            ]);

            $contract->forceFill(['invoice_id' => $invoice->id])->save();

            return $invoice;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bozza di fattura del pacchetto pronta: controllala ed emettila.')]);

        return to_route('sales.invoices.show', $invoice);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ServiceContract $contract = null): array
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')->where('role', 'customer')->where(
                fn ($query) => $query->whereNull('deleted_at')->when($contract?->organization_id, fn ($query, int $id) => $query->orWhere('id', $id)),
            )],
            'title' => ['required', 'string', 'max:255'],
            'hours' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'mode' => ['nullable', Rule::enum(InterventionMode::class)],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', Rule::exists('intervention_categories', 'id')],
            'price' => ['nullable', 'numeric', 'between:0,99999999'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'vat_rate_id' => ['nullable', 'integer', Rule::exists('vat_rates', 'id')],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [], [
            'organization_id' => 'cliente',
            'title' => 'descrizione',
            'hours' => 'ore',
            'starts_on' => 'inizio validità',
            'ends_on' => 'fine validità',
            'price' => 'prezzo',
            'manager_id' => 'responsabile',
        ]);

        // The customer can't change once hours were used.
        if ($contract && (int) $data['organization_id'] !== $contract->organization_id && $contract->interventions()->exists()) {
            $data['organization_id'] = $contract->organization_id;
        }

        return [...$data, 'price' => $data['price'] ?? 0, 'category_ids' => array_values(array_map('intval', $data['category_ids'] ?? [])), 'is_active' => $request->boolean('is_active', true)];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(?ServiceContract $contract = null): array
    {
        return [
            'customers' => $this->organizationOptions(OrganizationRole::Customer, $contract?->organization_id),
            'categories' => InterventionCategory::query()->ordered()->get()->map(fn (InterventionCategory $category) => ['value' => $category->id, 'title' => $category->name]),
            'modes' => InterventionMode::options(),
            'vatRates' => VatRateResource::collection(VatRate::query()->where('is_active', true)->ordered()->get())->resolve(),
            'products' => Product::query()->active()->orderBy('name')->limit(500)->get(['id', 'code', 'name'])
                ->map(fn (Product $product) => ['value' => $product->id, 'title' => "{$product->code} · {$product->name}"]),
            'managers' => User::query()->active()->orderBy('name')->get(['id', 'name'])
                ->filter(fn (User $user) => $user->can(Permission::ContractsManage->value) || $user->can(Permission::InterventionsSupervise->value))
                ->map(fn (User $user) => ['value' => $user->id, 'title' => $user->name])->values(),
        ];
    }
}
