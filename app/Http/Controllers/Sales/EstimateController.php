<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Estimates\AcceptEstimate;
use App\Actions\Estimates\GenerateAdvantages;
use App\Actions\Estimates\SaveEstimate;
use App\Enums\OrganizationRole;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Support\Ai\AiException;
use App\Support\Ai\LinkReader;
use App\Support\Ai\OpenRouterClient;
use App\Support\EstimatePdf;
use App\Support\Estimates\EstimateCalculator;
use App\Support\Fiscal\FiscalCodes;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Preventivatore (experimental): items at cost by category, markup, monthly fee over the term,
 * report for the customer, acceptance into a service contract.
 */
class EstimateController extends Controller
{
    use ProvidesDocumentEditorOptions;

    public function index(Request $request, EstimateCalculator $calculator): Response
    {
        $this->authorize(Permission::EstimatesManage->value);

        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;

        $estimates = Estimate::query()->with(['organization', 'items'])->search($search)->orderByDesc('id')->paginate(50)->withQueryString();

        return Inertia::render('sales/estimates/Index', [
            'estimates' => $estimates->through(fn (Estimate $estimate) => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'title' => $estimate->title,
                'organization' => $estimate->organization?->name,
                'status' => $estimate->status,
                'months' => $estimate->months,
                ...collect($calculator->summarize($estimate))->only(['price', 'monthly', 'margin_percent'])->all(),
                'updated_at' => $estimate->updated_at->toIso8601String(),
            ]),
            'search' => $search ?? '',
        ]);
    }

    public function create(): Response
    {
        $this->authorize(Permission::EstimatesManage->value);

        return Inertia::render('sales/estimates/Edit', [
            'estimate' => null,
            ...$this->editorProps(),
        ]);
    }

    public function store(Request $request, SaveEstimate $save): RedirectResponse
    {
        $this->authorize(Permission::EstimatesManage->value);

        $estimate = $save->handle(new Estimate, [...$this->validated($request), 'owner_id' => $request->user()->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proposta :number salvata.', ['number' => $estimate->number])]);

        return to_route('sales.estimates.show', $estimate);
    }

    public function show(Estimate $estimate): Response
    {
        $this->authorize(Permission::EstimatesManage->value);

        $estimate->load(['items', 'organization', 'subscription:id,number']);

        return Inertia::render('sales/estimates/Edit', [
            'estimate' => [
                ...$estimate->only(['id', 'number', 'organization_id', 'title', 'intro', 'months', 'upfront', 'advantages', 'notes', 'status']),
                'markup_percent' => (float) $estimate->markup_percent,
                'upfront' => (float) $estimate->upfront,
                'category_markups' => (object) ($estimate->category_markups ?? []),
                'starts_on' => $estimate->starts_on?->toDateString(),
                'subscription' => $estimate->subscription?->only(['id', 'number']),
                'items' => $estimate->items->map(fn (EstimateItem $item) => [
                    'id' => $item->id,
                    'category' => $item->category,
                    'description' => $item->description,
                    'url' => $item->url,
                    'quantity' => (float) $item->quantity,
                    'unit_cost' => (float) $item->unit_cost,
                    'note' => $item->note,
                ]),
            ],
            ...$this->editorProps($estimate->organization_id),
        ]);
    }

    public function update(Request $request, Estimate $estimate, SaveEstimate $save): RedirectResponse
    {
        $this->authorize(Permission::EstimatesManage->value);

        $save->handle($estimate, $this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proposta salvata.')]);

        return back();
    }

    public function destroy(Estimate $estimate): RedirectResponse
    {
        $this->authorize(Permission::EstimatesManage->value);
        abort_if($estimate->isAccepted(), 409, __('La proposta è accettata: resta come riferimento del contratto.'));

        $estimate->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proposta eliminata.')]);

        return to_route('sales.estimates.index');
    }

    /**
     * Name and cost of a product read from its web page.
     */
    public function readLink(Request $request, LinkReader $reader): JsonResponse
    {
        $this->authorize(Permission::EstimatesManage->value);

        $url = $request->validate(['url' => ['required', 'url:http,https', 'max:2000']], [], ['url' => 'link'])['url'];

        try {
            return response()->json(['data' => [...$reader->read($url), 'url' => $url]]);
        } catch (AiException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }
    }

    /**
     * Advantages of the solution drafted by the AI (saved data: save the proposal first).
     */
    public function advantages(Estimate $estimate, GenerateAdvantages $generate): JsonResponse
    {
        $this->authorize(Permission::EstimatesManage->value);
        $this->authorize(Permission::AiUse->value);

        try {
            return response()->json(['data' => $generate->handle($estimate)]);
        } catch (AiException $exception) {
            throw ValidationException::withMessages(['advantages' => $exception->getMessage()]);
        }
    }

    public function pdf(Estimate $estimate, EstimatePdf $pdf): HttpResponse
    {
        $this->authorize(Permission::EstimatesManage->value);

        return $pdf->make($estimate)->stream($pdf->filename($estimate));
    }

    /**
     * The customer said yes: monthly service contract for the term (and the upfront invoice draft).
     */
    public function accept(Request $request, Estimate $estimate, AcceptEstimate $accept): RedirectResponse
    {
        $this->authorize(Permission::EstimatesManage->value);
        $this->authorize(Permission::SubscriptionsManage->value);

        ['subscription' => $subscription, 'invoice' => $invoice] = $accept->handle($estimate, $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => $invoice
            ? __('Proposta accettata: creato il contratto :number e la bozza di fattura dell\'anticipo.', ['number' => $subscription->number])
            : __('Proposta accettata: creato il contratto :number.', ['number' => $subscription->number])]);

        return to_route('sales.subscriptions.show', $subscription);
    }

    /**
     * @return array<string, mixed>
     */
    private function editorProps(?int $organizationId = null): array
    {
        return [
            'customers' => $this->organizationOptions(OrganizationRole::Customer, $organizationId),
            // Categories used in the other proposals, as suggestions.
            'categorySuggestions' => EstimateItem::query()->whereNotNull('category')->distinct()->orderBy('category')->limit(100)->pluck('category'),
            'canUseAi' => request()->user()->can(Permission::AiUse->value) && app(OpenRouterClient::class)->isConfigured(),
            'vatFree' => in_array((string) app(Settings::class)->get('fiscal.regime'), FiscalCodes::VAT_FREE_REGIMES, true),
            'canAccept' => request()->user()->can(Permission::SubscriptionsManage->value),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')->where('role', 'customer')],
            'title' => ['required', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:5000'],
            'months' => ['required', 'integer', 'between:1,120'],
            'markup_percent' => ['required', 'numeric', 'between:0,1000'],
            'category_markups' => ['nullable', 'array'],
            'category_markups.*' => ['nullable', 'numeric', 'between:0,1000'],
            'upfront' => ['nullable', 'numeric', 'between:0,99999999'],
            'starts_on' => ['nullable', 'date'],
            'advantages' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['nullable', 'array', 'max:300'],
            'items.*.category' => ['nullable', 'string', 'max:60'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.url' => ['nullable', 'url:http,https', 'max:2000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'between:0,99999999'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'organization_id' => 'cliente',
            'title' => 'titolo',
            'months' => 'durata',
            'markup_percent' => 'ricarico',
            'upfront' => 'anticipo',
            'items.*.description' => 'descrizione',
            'items.*.quantity' => 'quantità',
            'items.*.unit_cost' => 'costo',
            'items.*.url' => 'link',
        ]);

        return [
            ...$data,
            'upfront' => $data['upfront'] ?? 0,
            'category_markups' => collect($data['category_markups'] ?? [])->filter(fn ($value) => $value !== null && $value !== '')->map(fn ($value) => (float) $value)->all() ?: null,
            'items' => $data['items'] ?? [],
        ];
    }
}
