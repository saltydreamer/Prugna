<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Quotes\SaveQuote;
use App\Enums\Permission;
use App\Enums\QuoteStatus;
use App\Enums\UnitOfMeasure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\QuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Http\Resources\VatRateResource;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\Quote;
use App\Models\User;
use App\Models\VatRate;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    private const SORTABLE = ['number', 'issue_date', 'valid_until', 'total'];

    public function __construct(private Settings $settings) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Quote::class);

        $user = $request->user();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(QuoteStatus::class)],
            'owner' => ['nullable', 'integer'],
            'organization' => ['nullable', 'integer'],
            'sort_by' => ['nullable', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        $base = Quote::query()->visibleTo($user)
            ->when($filters['owner'] ?? null, fn ($query, int $owner) => $query->where('owner_id', $owner))
            ->when($filters['organization'] ?? null, fn ($query, int $organization) => $query->where('organization_id', $organization));

        $quotes = (clone $base)
            ->with(['owner', 'organization'])
            ->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy($filters['sort_by'] ?? 'number', $filters['sort_dir'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return Inertia::render('sales/quotes/Index', [
            'quotes' => QuoteResource::collection($quotes),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? null,
                'owner' => isset($filters['owner']) ? (int) $filters['owner'] : null,
                'organization' => isset($filters['organization']) ? (int) $filters['organization'] : null,
                'sort_by' => $filters['sort_by'] ?? 'number',
                'sort_dir' => $filters['sort_dir'] ?? 'desc',
                'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'statuses' => QuoteStatus::options(),
            'counts' => (clone $base)->toBase()->selectRaw('status, count(*) as aggregate, sum(total) as amount')->groupBy('status')->get()
                ->mapWithKeys(fn (object $row) => [$row->status => ['count' => (int) $row->aggregate, 'amount' => (float) $row->amount]]),
            'owners' => $user->can(Permission::QuotesSupervise->value) ? $this->ownerOptions() : null,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Quote::class);

        $organization = $request->integer('organization')
            ? Organization::query()->find($request->integer('organization'))
            : null;

        return Inertia::render('sales/quotes/Edit', [
            'quote' => null,
            'defaults' => [
                'organization_id' => $organization?->id,
                'owner_id' => $request->user()->id,
                'issue_date' => today()->toDateString(),
                'valid_until' => today()->addDays((int) $this->settings->get('quotes.validity_days'))->toDateString(),
                'terms' => $this->settings->get('quotes.terms'),
                'payment_term_id' => $organization?->payment_term_id ?? PaymentTerm::default()?->id,
            ],
            ...$this->editorOptions($request->user()),
        ]);
    }

    public function store(QuoteRequest $request, SaveQuote $saveQuote): RedirectResponse
    {
        $quote = $saveQuote->handle(new Quote, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preventivo :number creato.', ['number' => $quote->number])]);

        return to_route('sales.quotes.show', $quote);
    }

    public function show(Request $request, Quote $quote): Response
    {
        $this->authorize('view', $quote);

        $quote->load(['lines', 'owner', 'contact', 'organization']);

        return Inertia::render('sales/quotes/Show', [
            'quote' => QuoteResource::make($quote)->resolve(),
            'publicUrl' => $quote->publicUrl(),
            'organizationArchived' => $quote->organization?->trashed() ?? false,
        ]);
    }

    public function edit(Request $request, Quote $quote): Response|RedirectResponse
    {
        $this->authorize('update', $quote);

        if (! $quote->isDraft()) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('Per modificare un preventivo già inviato riaprilo come bozza.')]);

            return to_route('sales.quotes.show', $quote);
        }

        return Inertia::render('sales/quotes/Edit', [
            'quote' => QuoteResource::make($quote->load('lines'))->resolve(),
            'defaults' => null,
            ...$this->editorOptions($request->user(), $quote),
        ]);
    }

    public function update(QuoteRequest $request, Quote $quote, SaveQuote $saveQuote): RedirectResponse
    {
        abort_unless($quote->isDraft(), 409, __('Solo le bozze possono essere modificate.'));

        $saveQuote->handle($quote, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preventivo salvato.')]);

        return to_route('sales.quotes.show', $quote);
    }

    public function destroy(Quote $quote): RedirectResponse
    {
        $this->authorize('delete', $quote);

        $quote->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bozza :number eliminata.', ['number' => $quote->number])]);

        return to_route('sales.quotes.index');
    }

    public function duplicate(Request $request, Quote $quote, SaveQuote $saveQuote): RedirectResponse
    {
        $this->authorize('view', $quote);
        $this->authorize('create', Quote::class);

        $copy = $saveQuote->duplicate($quote->load('lines'), $request->user()->id, (int) $this->settings->get('quotes.validity_days'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Creata la bozza :number, copia di :source.', ['number' => $copy->number, 'source' => $quote->number])]);

        return to_route('sales.quotes.edit', $copy);
    }

    public function reopen(Quote $quote): RedirectResponse
    {
        $this->authorize('update', $quote);
        abort_unless($quote->status->canReopen(), 409);

        $quote->reopen();

        return to_route('sales.quotes.edit', $quote);
    }

    /**
     * Sent without using the built-in email (e.g. by hand or by phone).
     */
    public function markSent(Quote $quote): RedirectResponse
    {
        $this->authorize('update', $quote);
        abort_unless($quote->isDraft(), 409);

        $quote->markSent();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preventivo segnato come inviato.')]);

        return back();
    }

    /**
     * Records an answer received outside the online page.
     */
    public function decide(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorize('update', $quote);
        abort_unless(in_array($quote->status, [QuoteStatus::Sent, QuoteStatus::Expired], true), 409);

        $data = $request->validate([
            'accepted' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $quote->decide((bool) $data['accepted'], $request->user()->name, $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['accepted'] ? __('Preventivo accettato.') : __('Preventivo rifiutato.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function editorOptions(User $user, ?Quote $quote = null): array
    {
        return [
            'organizations' => Organization::query()
                ->when($quote, fn ($query) => $query->withTrashed()->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('id', $quote->organization_id)))
                ->orderBy('name')
                ->get(['id', 'name', 'vat_number'])
                ->map(fn (Organization $organization) => [
                    'value' => $organization->id,
                    'title' => $organization->name,
                    'subtitle' => $organization->vat_number ? "P.IVA {$organization->vat_number}" : null,
                ]),
            'vatRates' => VatRateResource::collection(
                VatRate::query()->where('is_active', true)->ordered()->get()
            )->resolve(),
            'paymentTerms' => PaymentTerm::query()->active()->ordered()->get(['id', 'name'])
                ->map(fn (PaymentTerm $term) => ['value' => $term->id, 'title' => $term->name]),
            'units' => UnitOfMeasure::options(),
            'owners' => $user->can(Permission::QuotesSupervise->value) ? $this->ownerOptions() : null,
        ];
    }

    /**
     * Users who can own quotes.
     *
     * @return list<array{value: int, title: string}>
     */
    private function ownerOptions(): array
    {
        return User::query()->active()->orderBy('name')->get()
            ->filter(fn (User $user) => $user->can(Permission::QuotesView->value))
            ->map(fn (User $user) => ['value' => $user->id, 'title' => $user->name])
            ->values()
            ->all();
    }
}
