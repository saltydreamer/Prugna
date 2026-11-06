<?php

namespace App\Actions\Registry;

use App\Enums\LocationType;
use App\Enums\OrganizationType;
use App\Models\Location;
use App\Models\Organization;
use App\Rules\ItalianTaxCode;
use App\Rules\ItalianVatNumber;
use App\Support\Sdi\OpenapiClient;
use App\Support\Sdi\OpenapiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Company data from the Registro Imprese (Openapi "Company", IT-advanced): fills a new customer or
 * supplier from its VAT number and proposes the changes to an existing one (registered office, PEC,
 * recipient code...). Nothing changes until the user picks what to update.
 */
class CompanyRegistryData
{
    /** Fields that can be updated from the registry, with their labels. */
    public const FIELDS = [
        'name' => 'Ragione sociale',
        'vat_number' => 'Partita IVA',
        'tax_code' => 'Codice fiscale',
        'sdi_code' => 'Codice destinatario SDI',
        'pec' => 'PEC',
        'location' => 'Sede legale',
    ];

    /** The data read for an organization is kept while the user chooses what to update. */
    private const CACHE_MINUTES = 30;

    private const STATUSES = [
        'ATTIVA' => 'Attiva',
        'REGISTRATA' => 'Registrata',
        'INATTIVA' => 'Inattiva',
        'SOSPESA' => 'Sospesa',
        'IN_ISCRIZIONE' => 'In iscrizione',
        'CESSATA' => 'Cessata',
    ];

    /** Words kept lowercase inside an address or a city ("Via della Repubblica", "Reggio nell'Emilia"). */
    private const SMALL_WORDS = ['di', 'del', 'dello', 'della', 'dei', 'degli', 'delle', 'da', 'dal', 'dallo', 'dalla', 'dai', 'dagli', 'dalle', 'e', 'ed', 'in', 'nel', 'nello', 'nella', 'nei', 'negli', 'nelle', 'a', 'al', 'allo', 'alla', 'ai', 'agli', 'alle', 'su', 'sul', 'sullo', 'sulla', 'sui', 'sugli', 'sulle', 'per', 'con', 'tra', 'fra'];

    public function __construct(private OpenapiClient $client) {}

    /**
     * A VAT number (11 digits, "IT" optional) or a tax code (16 characters, or 11 digits) ready for the registry.
     *
     * @throws ValidationException when it is neither
     */
    public static function code(?string $value, string $attribute = 'code'): string
    {
        $vat = ItalianVatNumber::normalize((string) $value);

        if (preg_match('/^\d{11}$/', $vat)) {
            return $vat;
        }

        $taxCode = ItalianTaxCode::normalize((string) $value);

        if (preg_match('/^[A-Z0-9]{16}$/', $taxCode)) {
            return $taxCode;
        }

        throw ValidationException::withMessages([$attribute => __('Indica una partita IVA italiana (11 cifre) o un codice fiscale.')]);
    }

    /**
     * The company with this VAT number or tax code, as the fields of a customer or supplier.
     *
     * @return array<string, mixed>
     *
     * @throws OpenapiException
     */
    public function lookup(string $code): array
    {
        return $this->normalize($this->client->company($code));
    }

    /**
     * Reads the registry for an organization and lists what differs from its data.
     *
     * @return array{company: array<string, mixed>, changes: list<array{field: string, label: string, current: ?string, proposed: string}>}
     *
     * @throws OpenapiException
     * @throws ValidationException without a VAT number or a tax code
     */
    public function compare(Organization $organization): array
    {
        $code = $this->organizationCode($organization);
        $company = $this->lookup($code);

        Cache::put($this->cacheKey($organization), $company, now()->addMinutes(self::CACHE_MINUTES));

        return ['company' => $company, 'changes' => $this->changes($organization, $company)];
    }

    /**
     * Updates the chosen fields with the data read by compare() (read again if too much time has passed).
     *
     * @param  list<string>  $fields
     * @return list<string> Labels of the fields updated.
     */
    public function apply(Organization $organization, array $fields): array
    {
        $company = Cache::get($this->cacheKey($organization)) ?? $this->lookup($this->organizationCode($organization));
        $changes = collect($this->changes($organization, $company))->keyBy('field')->only($fields);

        if ($changes->has('vat_number')
            && Organization::withTrashed()->whereKeyNot($organization->id)->where('vat_number', $company['vat_number'])->exists()) {
            throw ValidationException::withMessages(['fields' => __("Esiste già un'organizzazione con la partita IVA :vat (controlla anche tra le archiviate).", ['vat' => $company['vat_number']])]);
        }

        DB::transaction(function () use ($organization, $company, $changes): void {
            $attributes = $changes->keys()->reject(fn (string $field) => $field === 'location')
                ->mapWithKeys(fn (string $field) => [$field => $company[$field]])
                ->all();

            if ($attributes !== []) {
                $organization->update($attributes);
            }

            if ($changes->has('location')) {
                $location = $this->registeredOffice($organization);
                $address = collect($company['location'])->only(['address', 'postal_code', 'city', 'province', 'country'])->all();

                if ($location) {
                    $location->update($address);
                } else {
                    $organization->locations()->create([...$address, 'name' => __('Sede legale'), 'type' => LocationType::Registered]);
                }
            }
        });

        Cache::forget($this->cacheKey($organization));

        return $changes->pluck('label')->values()->all();
    }

    /**
     * @param  array<string, mixed>  $company
     * @return list<array{field: string, label: string, current: ?string, proposed: string}>
     */
    public function changes(Organization $organization, array $company): array
    {
        $location = $this->registeredOffice($organization);
        $changes = [];

        $propose = function (string $field, ?string $current, ?string $proposed, ?string $label = null) use (&$changes): void {
            if (filled($proposed) && self::comparable($current) !== self::comparable($proposed)) {
                $changes[] = ['field' => $field, 'label' => $label ?? self::FIELDS[$field], 'current' => filled($current) ? $current : null, 'proposed' => $proposed];
            }
        };

        $propose('name', $organization->name, $company['name']);
        // Found by tax code: the VAT number only when missing (it is how the organization is known elsewhere).
        if (blank($organization->vat_number)) {
            $propose('vat_number', null, $company['vat_number']);
        }
        $propose('tax_code', $organization->tax_code, $company['tax_code']);
        // Public bodies are invoiced to the 6 character office code (IPA), not to a recipient code.
        if ($organization->type !== OrganizationType::PublicBody) {
            $propose('sdi_code', $organization->sdi_code, $company['sdi_code']);
        }
        $propose('pec', $organization->pec, $company['pec']);

        if ($company['location']['address'] ?? null) {
            $current = $location ? self::addressLine($location->only(['address', 'postal_code', 'city', 'province'])) : null;
            $propose('location', $current, $company['location']['full'], $location ? null : __('Sede legale (nuova)'));
        }

        return $changes;
    }

    /**
     * The location of the registered office ("sede legale", the primary one first); null when there is none yet.
     */
    private function registeredOffice(Organization $organization): ?Location
    {
        return $organization->locations()->where('type', LocationType::Registered->value)->orderByDesc('is_primary')->orderBy('id')->first();
    }

    private function organizationCode(Organization $organization): string
    {
        foreach ([$organization->vat_number, $organization->tax_code] as $value) {
            try {
                return self::code($value, 'fields');
            } catch (ValidationException) {
                continue;
            }
        }

        throw ValidationException::withMessages(['fields' => __('Per cercare l\'azienda nel Registro Imprese serve la partita IVA italiana o il codice fiscale.')]);
    }

    private function cacheKey(Organization $organization): string
    {
        return "openapi-company:{$organization->id}";
    }

    /**
     * @param  array<string, mixed>  $data  An "IT-advanced" record.
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $office = (array) data_get($data, 'address.registeredOffice', []);
        $street = trim((string) ($office['streetName'] ?? '')) ?: trim(implode(' ', array_filter([$office['toponym'] ?? null, $office['street'] ?? null, $office['streetNumber'] ?? null])));
        $location = [
            'address' => self::titleCase($street),
            'postal_code' => filled($office['zipCode'] ?? null) ? (string) $office['zipCode'] : null,
            'city' => self::titleCase($office['town'] ?? null),
            'province' => filled($office['province'] ?? null) ? strtoupper((string) $office['province']) : null,
            'country' => 'IT',
        ];
        $location['full'] = self::addressLine($location);

        $status = strtoupper((string) ($data['activityStatus'] ?? ''));
        $endDate = $data['endDate'] ?? null;
        $sdiCode = strtoupper(trim((string) ($data['sdiCode'] ?? '')));
        $ateco = data_get($data, 'atecoClassification.ateco') ?? data_get($data, 'atecoClassification.ateco2022');
        $legalForm = (string) data_get($data, 'detailedLegalForm.description', '');
        $updated = $data['lastUpdateTimestamp'] ?? null;

        return [
            'name' => trim((string) ($data['companyName'] ?? '')) ?: null,
            'vat_number' => filled($data['vatCode'] ?? null) ? ItalianVatNumber::normalize((string) $data['vatCode']) : null,
            'tax_code' => filled($data['taxCode'] ?? null) ? ItalianTaxCode::normalize((string) $data['taxCode']) : null,
            'sdi_code' => $sdiCode !== '' ? $sdiCode : null,
            'pec' => filled($data['pec'] ?? null) ? mb_strtolower(trim((string) $data['pec'])) : null,
            'location' => $location,
            'status' => $status !== '' ? $status : null,
            'status_label' => self::STATUSES[$status] ?? ($status !== '' ? ucfirst(mb_strtolower($status)) : null),
            'ceased' => $status === 'CESSATA' || filled($endDate) || ($data['cessata'] ?? false) === true,
            'ceased_on' => filled($endDate) ? Carbon::parse($endDate)->toDateString() : null,
            'legal_form' => $legalForm !== '' ? mb_strtoupper(mb_substr($legalForm, 0, 1)).mb_strtolower(mb_substr($legalForm, 1)) : null,
            'ateco' => is_array($ateco) && filled($ateco['code'] ?? null) ? trim($ateco['code'].' '.($ateco['description'] ?? '')) : null,
            'rea' => filled($data['reaCode'] ?? null) ? trim(($data['cciaa'] ?? '').'-'.$data['reaCode'], '-') : null,
            'started_on' => filled($data['startDate'] ?? null) ? Carbon::parse($data['startDate'])->toDateString() : null,
            'updated_on' => is_numeric($updated) ? Carbon::createFromTimestamp((int) $updated)->toDateString() : null,
        ];
    }

    /**
     * "Viale F Tommaso Marinetti 221, 00143 Roma (RM)".
     *
     * @param  array<string, mixed>  $location
     */
    private static function addressLine(array $location): ?string
    {
        $city = trim(implode(' ', array_filter([$location['postal_code'] ?? null, $location['city'] ?? null])));

        if (filled($location['province'] ?? null)) {
            $city .= " ({$location['province']})";
        }

        $line = implode(', ', array_filter([$location['address'] ?? null, $city]));

        return $line !== '' ? $line : null;
    }

    /**
     * Case, spaces and punctuation don't count: "ACME S.R.L." and "Acme Srl" are the same name.
     */
    private static function comparable(?string $value): string
    {
        return mb_strtolower((string) preg_replace('/[^\pL\pN]+/u', '', (string) $value));
    }

    /**
     * The registry writes in capitals: "VIA DELL'INDUSTRIA 5" → "Via dell'Industria 5".
     */
    private static function titleCase(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', (string) $value));

        if ($value === '') {
            return null;
        }

        $words = explode(' ', mb_strtolower($value));

        foreach ($words as $index => $word) {
            if ($index > 0 && in_array($word, self::SMALL_WORDS, true)) {
                continue;
            }

            // "dell'industria" → "dell'Industria", "l'aquila" → "L'Aquila".
            if (preg_match("/^([\\pL]+)'(.+)$/u", $word, $match)) {
                $prefix = $index > 0 ? $match[1] : mb_convert_case($match[1], MB_CASE_TITLE);
                $words[$index] = $prefix."'".mb_convert_case($match[2], MB_CASE_TITLE);

                continue;
            }

            $words[$index] = mb_convert_case($word, MB_CASE_TITLE);
        }

        return implode(' ', $words);
    }
}
