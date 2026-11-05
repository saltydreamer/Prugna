<?php

namespace App\Support\Dolibarr;

use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Models\DolibarrLink;
use App\Models\PaymentTerm;
use App\Models\User;
use App\Models\VatRate;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Shared state of a Dolibarr import: the links Dolibarr row → Prugna record (what makes the
 * import repeatable) and the translations of Dolibarr dictionaries into Prugna values.
 */
class DolibarrMapper
{
    /** @var array<string, array<int, array{type: string, id: int}>> */
    private array $links = [];

    /** @var array<string, array<int, true>> Prugna ids linked from each source. */
    private array $modelIds = [];

    /** @var array<int, ?int> Dolibarr user → Prugna user. */
    private array $users = [];

    /** @var array<string, int> */
    private array $usersByEmail = [];

    /** @var array<string, ?int> */
    private array $vatRates = [];

    /** @var array<string, ?int> */
    private array $paymentTerms = [];

    public readonly string $regime;

    public function __construct(public readonly DolibarrSource $source, public readonly ImportReport $report, Settings $settings)
    {
        $this->regime = (string) $settings->get('fiscal.regime');

        foreach (DolibarrLink::query()->get(['source', 'source_id', 'linkable_type', 'linkable_id']) as $link) {
            $this->links[$link->source][(int) $link->source_id] = ['type' => $link->linkable_type, 'id' => (int) $link->linkable_id];
        }

        foreach (User::query()->get(['id', 'email']) as $user) {
            $this->usersByEmail[Str::lower($user->email)] = $user->id;
        }
    }

    /**
     * The Prugna record imported from a Dolibarr row, if it still exists.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel|null
     */
    public function linked(string $source, int $id, string $class): ?Model
    {
        $link = $this->links[$source][$id] ?? null;

        if ($link === null || $link['type'] !== (new $class)->getMorphClass()) {
            return null;
        }

        $query = $class::query();

        if (method_exists($class, 'bootSoftDeletes')) {
            $query->withTrashed();
        }

        return $query->find($link['id']);
    }

    public function linkedId(string $source, ?int $id): ?int
    {
        return $id === null ? null : ($this->links[$source][$id]['id'] ?? null);
    }

    public function remember(string $source, int $id, Model $model, ?string $reference = null): void
    {
        DolibarrLink::query()->updateOrCreate(
            ['source' => $source, 'source_id' => $id],
            ['linkable_type' => $model->getMorphClass(), 'linkable_id' => $model->getKey(), 'reference' => $reference !== null ? mb_substr($reference, 0, 255) : null],
        );

        $this->links[$source][$id] = ['type' => $model->getMorphClass(), 'id' => (int) $model->getKey()];

        if (isset($this->modelIds[$source])) {
            $this->modelIds[$source][(int) $model->getKey()] = true;
        }
    }

    /**
     * Prugna ids imported from a source (e.g. the invoices imported from Dolibarr).
     *
     * @return list<int>
     */
    public function linkedModelIds(string $source): array
    {
        return array_keys($this->modelIdsOf($source));
    }

    public function isLinkedModel(string $source, int $modelId): bool
    {
        return isset($this->modelIdsOf($source)[$modelId]);
    }

    /**
     * @return array<int, true>
     */
    private function modelIdsOf(string $source): array
    {
        if (! isset($this->modelIds[$source])) {
            $this->modelIds[$source] = [];

            foreach ($this->links[$source] ?? [] as $link) {
                $this->modelIds[$source][$link['id']] = true;
            }
        }

        return $this->modelIds[$source];
    }

    /**
     * Dolibarr ids already linked for a source.
     *
     * @return list<int>
     */
    public function linkedIds(string $source): array
    {
        return array_keys($this->links[$source] ?? []);
    }

    /**
     * Sequence of an imported document (orders, supplier orders, interventions, contracts): the
     * one it already has, or the next free one of the year. The printed number stays Dolibarr's.
     *
     * @param  class-string<Model>  $class
     */
    public function sequence(string $class, ?int $year, ?Model $current): int
    {
        if ($current && $current->getAttribute('sequence') && ($year === null || (int) $current->getAttribute('year') === $year)) {
            return (int) $current->getAttribute('sequence');
        }

        return (int) $class::query()->when($year !== null, fn ($query) => $query->where('year', $year))->max('sequence') + 1;
    }

    /**
     * A document number free in Prugna: Dolibarr's reference, marked when another record uses it.
     *
     * @param  class-string<Model>  $class
     */
    public function number(string $class, string $reference, ?Model $current, int $length = 32): string
    {
        $number = mb_substr($reference, 0, $length);
        $taken = $class::query()->where('number', $number)->when($current, fn ($query) => $query->whereKeyNot($current->getKey()))->exists();

        return $taken ? mb_substr($reference, 0, $length - 4).' (D)' : $number;
    }

    /**
     * Prugna user with the same email as the Dolibarr user (users are never created: the
     * report lists the missing ones, and importing again after creating them links them).
     */
    public function user(mixed $dolibarrId): ?int
    {
        $dolibarrId = DolibarrSource::int($dolibarrId);

        if (! $dolibarrId) {
            return null;
        }

        if (array_key_exists($dolibarrId, $this->users)) {
            return $this->users[$dolibarrId];
        }

        $user = $this->source->dictionary('user', $dolibarrId);
        $email = Str::lower(trim((string) ($user->email ?? '')));
        $id = $email !== '' ? ($this->usersByEmail[$email] ?? null) : null;

        if ($user && $id === null) {
            $name = trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: $user->login;
            $this->report->warn(__('Utenti Dolibarr senza un utente Prugna con la stessa email: i loro documenti restano senza responsabile. Crea gli utenti e importa di nuovo per collegarli.'), "{$name} ({$user->login})");
        }

        return $this->users[$dolibarrId] = $id;
    }

    /**
     * VAT rate for a document line: rates above zero by value (created inactive when missing),
     * zero rates by FatturaPA nature.
     */
    public function vatRate(mixed $rate, ?string $nature): ?int
    {
        $rate = round((float) $rate, 2);
        $key = $rate.'|'.($rate > 0 ? '' : $nature);

        if (array_key_exists($key, $this->vatRates)) {
            return $this->vatRates[$key];
        }

        $query = VatRate::query()->where('rate', $rate)->orderByDesc('is_active')->orderBy('sort');

        if ($rate == 0) {
            if ($nature === null) {
                return $this->vatRates[$key] = null;
            }

            $query->where('nature', $nature);
        }

        $vatRate = $query->first() ?? VatRate::query()->forceCreate([
            'name' => $rate > 0 ? "IVA {$rate}%" : "Natura {$nature}",
            'rate' => $rate,
            'nature' => $rate > 0 ? null : $nature,
            'is_active' => false,
            'sort' => 900,
        ]);

        return $this->vatRates[$key] = $vatRate->id;
    }

    /**
     * FatturaPA nature of a zero-rate line: the VAT code of the line when it is a nature
     * (N2.2...), "N1" for the stamp duty refund, then the third party exemption code, then
     * "N2.2" for the flat-rate regime (RF19).
     */
    public function nature(mixed $rate, ?string $vatCode, ?string $description, ?string $fallback = null): ?string
    {
        if (round((float) $rate, 2) != 0) {
            return null;
        }

        foreach ([$vatCode, $fallback] as $code) {
            if ($code !== null && preg_match('/^N\d(\.\d+)?$/i', trim($code))) {
                return strtoupper(trim($code));
            }
        }

        if ($description !== null && preg_match('/\bbollo\b/i', $description)) {
            return 'N1';
        }

        return $this->regime === 'RF19' ? 'N2.2' : null;
    }

    /**
     * Payment term from Dolibarr's condition (days, end of month) and mode (bank transfer...),
     * reusing an equivalent Prugna term or creating it.
     */
    public function paymentTerm(mixed $conditionId, mixed $modeId): ?PaymentTerm
    {
        $condition = $this->source->dictionary('c_payment_term', DolibarrSource::int($conditionId));
        $mode = $this->source->dictionary('c_paiement', DolibarrSource::int($modeId), 'id');

        if (! $condition && ! $mode) {
            return null;
        }

        $method = $this->fatturaPaMethod($modeId) ?? 'MP05';
        $days = (int) ($condition->nbjour ?? 0);
        $endOfMonth = in_array((int) ($condition->type_cdr ?? 0), [1, 2], true);
        $key = "{$method}|{$days}|".(int) $endOfMonth;

        if (! array_key_exists($key, $this->paymentTerms)) {
            $term = PaymentTerm::query()->where('method', $method)->where('days', $days)->where('end_of_month', $endOfMonth)->orderByDesc('is_active')->orderBy('sort')->first();

            $term ??= PaymentTerm::query()->forceCreate([
                'name' => mb_substr(trim(implode(' ', array_filter([DolibarrSource::line($mode->libelle ?? null), DolibarrSource::line($condition->libelle ?? null)]))) ?: "{$method} {$days} gg", 0, 255),
                'method' => $method,
                'days' => $days,
                'end_of_month' => $endOfMonth,
                'sort' => 500,
            ]);

            $this->paymentTerms[$key] = $term->id;
        }

        return PaymentTerm::query()->find($this->paymentTerms[$key]);
    }

    /**
     * FatturaPA payment method (MPxx) from a Dolibarr payment mode.
     */
    public function fatturaPaMethod(mixed $modeId): ?string
    {
        $code = strtoupper((string) ($this->source->dictionary('c_paiement', DolibarrSource::int($modeId), 'id')->code ?? ''));

        return match (true) {
            $code === '' => null,
            $code === 'LIQ' => 'MP01',
            $code === 'CHQ' => 'MP02',
            in_array($code, ['CB', 'VAD', 'PPL', 'PAYPAL', 'STRIPE'], true) => 'MP08',
            $code === 'PRE' => 'MP19',
            in_array($code, ['LCR', 'RIBA', 'TRA'], true) => 'MP12',
            default => 'MP05',
        };
    }

    /**
     * Payment method of an expense from a Dolibarr payment mode.
     */
    public function paymentMethod(mixed $modeId): ?PaymentMethod
    {
        $code = strtoupper((string) ($this->source->dictionary('c_paiement', DolibarrSource::int($modeId), 'id')->code ?? ''));

        return match (true) {
            $code === '' => null,
            in_array($code, ['VIR', 'TIP'], true) => PaymentMethod::BankTransfer,
            in_array($code, ['CB', 'VAD', 'PPL', 'PAYPAL', 'STRIPE'], true) => PaymentMethod::Card,
            $code === 'LIQ' => PaymentMethod::Cash,
            $code === 'PRE' => PaymentMethod::DirectDebit,
            in_array($code, ['LCR', 'RIBA', 'TRA'], true) => PaymentMethod::Riba,
            default => PaymentMethod::Other,
        };
    }

    /**
     * Unit of measure from Dolibarr's units dictionary.
     */
    public function unit(mixed $unitId): ?UnitOfMeasure
    {
        $unit = $this->source->dictionary('c_units', DolibarrSource::int($unitId));

        if (! $unit) {
            return null;
        }

        $codes = array_map(fn ($value) => strtoupper(trim((string) $value)), [$unit->code ?? '', $unit->short_label ?? '']);

        foreach ($codes as $code) {
            $match = match ($code) {
                'H', 'HR', 'HRS', 'ORE', 'ORA' => UnitOfMeasure::Hour,
                'D', 'DA', 'DAY', 'GG', 'G' => UnitOfMeasure::Day,
                'P', 'PC', 'PCE', 'PZ', 'U', 'UN', 'NR', 'NO' => UnitOfMeasure::Piece,
                'MO', 'MONTH', 'MESE', 'MESI' => UnitOfMeasure::Month,
                'Y', 'YR', 'AN', 'ANNO', 'ANNI' => UnitOfMeasure::Year,
                'M', 'LM' => UnitOfMeasure::Meter,
                'KM' => UnitOfMeasure::Kilometer,
                'M2', 'M²' => UnitOfMeasure::SquareMeter,
                'KG' => UnitOfMeasure::Kilogram,
                'L', 'LT' => UnitOfMeasure::Liter,
                'SET', 'FORFAIT', 'F' => UnitOfMeasure::Lump,
                default => null,
            };

            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Unit of a product without one in Dolibarr: pieces for goods; hours for services sold
     * by the hour (by their name), otherwise "a corpo".
     */
    public static function defaultUnit(ProductType $type, ?string $name): UnitOfMeasure
    {
        if ($type === ProductType::Product) {
            return UnitOfMeasure::Piece;
        }

        return preg_match('/\b(ora|ore|orari[ao]?|hour|hours)\b/i', (string) $name) ? UnitOfMeasure::Hour : UnitOfMeasure::Lump;
    }

    /**
     * ISO country code (default Italy).
     */
    public function country(mixed $countryId): string
    {
        $code = strtoupper((string) ($this->source->dictionary('c_country', DolibarrSource::int($countryId))->code ?? ''));

        return preg_match('/^[A-Z]{2}$/', $code) ? $code : 'IT';
    }

    /**
     * Province code (e.g. "MI") from Dolibarr's departments.
     */
    public function province(mixed $departmentId): ?string
    {
        $code = trim((string) ($this->source->dictionary('c_departements', DolibarrSource::int($departmentId))->code_departement ?? ''));

        return $code === '' ? null : mb_substr(strtoupper($code), 0, 8);
    }
}
