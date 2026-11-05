<?php

namespace App\Actions\Dolibarr;

use App\Enums\LocationType;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Contact;
use App\Models\Location;
use App\Models\Organization;
use App\Rules\ItalianTaxCode;
use App\Rules\ItalianVatNumber;
use App\Support\Dolibarr\DolibarrMapper;
use App\Support\Dolibarr\DolibarrSource as Source;
use Illuminate\Support\Str;
use stdClass;

/**
 * Third parties → customers and suppliers (two records when a third party is both),
 * with their registered office as primary location and their contacts.
 */
class ImportThirdParties
{
    /** @var array<int, stdClass> */
    private array $thirdParties;

    /** @var array<int, stdClass> */
    private array $extraFields;

    private ?string $sdiField = null;

    private ?string $pecField = null;

    public function __construct(private DolibarrMapper $map)
    {
        $this->thirdParties = $map->source->rows('societe')->keyBy('rowid')->all();
        $this->extraFields = $map->source->rows('societe_extrafields')->keyBy('fk_object')->all();

        foreach ($map->source->columns('societe_extrafields') as $column) {
            if ($this->sdiField === null && preg_match('/sdi|destinatario|coddest|cod_dest|univoco|fe_?code|fatturapa/i', $column)) {
                $this->sdiField = $column;
            } elseif ($this->pecField === null && preg_match('/pec/i', $column)) {
                $this->pecField = $column;
            }
        }
    }

    public function run(): void
    {
        $report = $this->map->report;
        $report->section('customers', __('Clienti'));
        $report->section('suppliers', __('Fornitori'));
        $report->section('locations', __('Sedi'));
        $report->section('contacts', __('Contatti'));

        if ($this->sdiField) {
            $report->note(__('Codice SDI letto dal campo aggiuntivo «:field» delle terze parti.', ['field' => $this->sdiField]));
        }

        if ($this->pecField) {
            $report->note(__('PEC letta dal campo aggiuntivo «:field» delle terze parti.', ['field' => $this->pecField]));
        }

        foreach ($this->thirdParties as $thirdParty) {
            foreach ($this->roles($thirdParty) as $role) {
                $this->save($thirdParty, $role);
            }
        }

        $this->contacts();
    }

    /**
     * The customer or supplier record of a third party, created when a document needs it
     * (e.g. an invoice to a third party flagged only as supplier in Dolibarr).
     */
    public function organization(mixed $thirdPartyId, OrganizationRole $role): ?Organization
    {
        $thirdPartyId = Source::int($thirdPartyId);

        if (! $thirdPartyId) {
            return null;
        }

        $organization = $this->map->linked($this->source($role), $thirdPartyId, Organization::class);

        if ($organization) {
            return $organization;
        }

        $thirdParty = $this->thirdParties[$thirdPartyId] ?? null;

        return $thirdParty ? $this->save($thirdParty, $role) : null;
    }

    /**
     * @return list<OrganizationRole>
     */
    private function roles(stdClass $thirdParty): array
    {
        $roles = [];

        // 1 customer, 2 prospect, 3 both; a third party with no role is kept as customer.
        if (in_array((int) $thirdParty->client, [1, 2, 3], true) || ! (int) $thirdParty->fournisseur) {
            $roles[] = OrganizationRole::Customer;
        }

        if ((int) $thirdParty->fournisseur === 1) {
            $roles[] = OrganizationRole::Supplier;
        }

        return $roles;
    }

    private function source(OrganizationRole $role): string
    {
        return 'societe.'.$role->value;
    }

    private function save(stdClass $thirdParty, OrganizationRole $role): Organization
    {
        $section = $role === OrganizationRole::Customer ? 'customers' : 'suppliers';
        $attributes = $this->attributes($thirdParty, $role);
        $organization = $this->map->linked($this->source($role), (int) $thirdParty->rowid, Organization::class);

        if ($organization) {
            $organization->forceFill($attributes)->save();
            $this->map->report->count($section, 'updated');
        } elseif ($organization = $this->existing($attributes, $role)) {
            // Already in Prugna (same VAT number or tax code): linked and aligned to Dolibarr.
            $organization->forceFill($attributes)->save();
            $this->map->report->count($section, 'linked');
        } else {
            $organization = Organization::query()->forceCreate([...$attributes, 'role' => $role, 'created_at' => Source::date($thirdParty->datec) ?? now()]);
            $this->map->report->count($section, 'created');
        }

        $this->map->remember($this->source($role), (int) $thirdParty->rowid, $organization, $thirdParty->nom);

        // Closed third parties are archived (read only until restored).
        $closed = (int) ($thirdParty->status ?? 1) === 0;

        if ($closed && ! $organization->trashed()) {
            $organization->delete();
        } elseif (! $closed && $organization->trashed()) {
            $organization->restore();
        }

        $this->location($thirdParty, $organization, $role);

        return $organization;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function existing(array $attributes, OrganizationRole $role): ?Organization
    {
        if (! $attributes['vat_number'] && ! $attributes['tax_code']) {
            return null;
        }

        return Organization::withTrashed()
            ->where('role', $role->value)
            ->whereNotIn('id', $this->map->linkedModelIds($this->source($role)))
            ->where(fn ($query) => $query
                ->when($attributes['vat_number'], fn ($query, $vat) => $query->orWhere('vat_number', $vat))
                ->when($attributes['tax_code'], fn ($query, $code) => $query->orWhere('tax_code', $code)))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(stdClass $thirdParty, OrganizationRole $role): array
    {
        $extra = $this->extraFields[(int) $thirdParty->rowid] ?? null;
        $vat = $this->vatNumber($thirdParty);
        $taxCode = Source::line($thirdParty->idprof4 ?? null, 32);
        $taxCode = $taxCode !== null ? mb_substr(ItalianTaxCode::normalize($taxCode), 0, 32) : null;
        $email = Source::line($thirdParty->email, 255);
        $pec = $this->pecField ? Source::line($extra?->{$this->pecField} ?? null, 255) : null;

        if ($pec === null && $email !== null && preg_match('/@(.+\.)?(pec|legalmail|postacert|cert|arubapec)\./i', $email)) {
            $pec = $email;
        }

        $term = $role === OrganizationRole::Customer
            ? $this->map->paymentTerm($thirdParty->cond_reglement ?? null, $thirdParty->mode_reglement ?? null)
            : $this->map->paymentTerm($thirdParty->cond_reglement_supplier ?? null, $thirdParty->mode_reglement_supplier ?? null);

        $typeCode = strtoupper((string) ($this->map->source->dictionary('c_typent', Source::int($thirdParty->fk_typent ?? null), 'id')->code ?? ''));

        return [
            'name' => Source::line($thirdParty->nom, 255) ?? Source::line($thirdParty->name_alias ?? null, 255) ?? __('Terza parte :id', ['id' => $thirdParty->rowid]),
            'type' => match (true) {
                $typeCode === 'TE_ADMIN' => OrganizationType::PublicBody,
                $typeCode === 'TE_PRIVATE' => OrganizationType::Other,
                default => OrganizationType::Company,
            },
            'vat_number' => $vat,
            'tax_code' => $taxCode,
            'sdi_code' => $this->sdiCode($thirdParty, $extra),
            'email' => $email,
            'pec' => $pec,
            'phone' => Source::line($thirdParty->phone ?? null, 32) ?? Source::line($thirdParty->phone_mobile ?? null, 32),
            'website' => Source::line($thirdParty->url ?? null, 255),
            'payment_term_id' => $term?->id,
            'notes' => Source::join(
                ($alias = Source::line($thirdParty->name_alias ?? null)) ? __('Nome alternativo: :name', ['name' => $alias]) : null,
                Source::text($thirdParty->note_public ?? null),
                Source::text($thirdParty->note_private ?? null),
                (int) $thirdParty->client === 2 ? __('Prospect in Dolibarr.') : null,
            ),
            'created_by' => $this->map->user($thirdParty->fk_user_creat ?? null),
        ];
    }

    private function vatNumber(stdClass $thirdParty): ?string
    {
        foreach ([$thirdParty->tva_intra ?? null, $thirdParty->siren ?? null] as $index => $value) {
            $value = Source::line($value, 32);

            if ($value === null) {
                continue;
            }

            $value = ItalianVatNumber::normalize($value);

            // The professional id 1 is used only when it looks like a VAT number.
            if ($index === 0 || preg_match('/^\d{11}$/', $value)) {
                return mb_substr($value, 0, 32);
            }
        }

        return null;
    }

    /**
     * SDI recipient code: from the extra field when there is one, otherwise from a professional
     * id holding a 7 character code.
     */
    private function sdiCode(stdClass $thirdParty, ?stdClass $extra): ?string
    {
        $candidates = $this->sdiField ? [$extra?->{$this->sdiField} ?? null] : [];
        array_push($candidates, $thirdParty->siret ?? null, $thirdParty->ape ?? null, $thirdParty->idprof5 ?? null, $thirdParty->idprof6 ?? null);

        foreach ($candidates as $index => $value) {
            $value = strtoupper((string) preg_replace('/\s+/', '', (string) $value));

            if (preg_match('/^[A-Z0-9]{7}$/', $value) || ($index === 0 && $this->sdiField && preg_match('/^[A-Z0-9]{6}$/', $value))) {
                return $value;
            }
        }

        return null;
    }

    private function location(stdClass $thirdParty, Organization $organization, OrganizationRole $role): void
    {
        $address = Source::line($thirdParty->address ?? null, 255);
        $city = Source::line($thirdParty->town ?? null, 255);

        if ($address === null && $city === null) {
            return;
        }

        $source = 'societe.location.'.$role->value;
        $attributes = [
            'address' => $address ?? '',
            'postal_code' => Source::line($thirdParty->zip ?? null, 16),
            'city' => $city ?? '',
            'province' => $this->map->province($thirdParty->fk_departement ?? null),
            'country' => $this->map->country($thirdParty->fk_pays ?? null),
        ];

        $location = $this->map->linked($source, (int) $thirdParty->rowid, Location::class);

        if ($location) {
            $location->forceFill($attributes)->save();
            $this->map->report->count('locations', 'updated');
        } elseif ($organization->locations()->exists()) {
            // A record already in Prugna keeps its own locations.
            return;
        } else {
            $location = $organization->locations()->forceCreate([...$attributes, 'name' => __('Sede legale'), 'type' => LocationType::Registered, 'is_primary' => true]);
            $this->map->report->count('locations', 'created');
        }

        $this->map->remember($source, (int) $thirdParty->rowid, $location);
    }

    private function contacts(): void
    {
        foreach ($this->map->source->each('socpeople') as $person) {
            $thirdParty = $this->thirdParties[(int) $person->fk_soc] ?? null;

            if (! $thirdParty) {
                $this->map->report->count('contacts', 'skipped');
                $this->map->report->warn(__('Contatti senza terza parte: non importati (in Prugna un contatto appartiene sempre a un cliente o fornitore).'), trim(($person->firstname ?? '').' '.($person->lastname ?? '')));

                continue;
            }

            foreach (OrganizationRole::cases() as $role) {
                $organization = $this->map->linked($this->source($role), (int) $thirdParty->rowid, Organization::class);

                if ($organization) {
                    $this->contact($person, $organization, $role);
                }
            }
        }
    }

    private function contact(stdClass $person, Organization $organization, OrganizationRole $role): void
    {
        $firstName = Source::line($person->firstname ?? null, 255);
        $lastName = Source::line($person->lastname ?? null, 255);
        $email = Source::line($person->email ?? null, 255);
        $address = Source::join(Source::line($person->address ?? null), trim(Source::line($person->zip ?? null).' '.Source::line($person->town ?? null)));

        $attributes = [
            'first_name' => $firstName ?? '',
            'last_name' => $lastName ?? ($firstName === null ? ($email ?? __('Contatto')) : ''),
            'job_title' => Source::line($person->poste ?? null, 255),
            'email' => $email,
            'phone' => Source::line($person->phone ?? null, 32) ?? Source::line($person->phone_perso ?? null, 32),
            'mobile' => Source::line($person->phone_mobile ?? null, 32),
            'notes' => Source::join(
                Source::text($person->note_public ?? null),
                Source::text($person->note_private ?? null),
                $address ? __('Indirizzo: :address', ['address' => Str::squish($address)]) : null,
                (int) ($person->statut ?? 1) === 0 ? __('Contatto disattivato in Dolibarr.') : null,
            ),
        ];

        $source = 'socpeople.'.$role->value;
        $contact = $this->map->linked($source, (int) $person->rowid, Contact::class);

        if ($contact) {
            $contact->forceFill($attributes)->save();
            $this->map->report->count('contacts', 'updated');
        } else {
            $contact = $organization->contacts()->forceCreate($attributes);
            $this->map->report->count('contacts', 'created');
        }

        $this->map->remember($source, (int) $person->rowid, $contact);
    }
}
