<?php

namespace App\Actions\Documents;

use App\Enums\OrganizationType;
use App\Models\AiExtraction;
use App\Models\Organization;
use App\Rules\ItalianTaxCode;
use App\Rules\ItalianVatNumber;

/**
 * Defaults of the customer / supplier form from a document read by the AI (business card, letterhead...).
 */
class OrganizationDraft
{
    /**
     * @return array{defaults: array<string, mixed>, review: array<string, mixed>}
     */
    public function fromExtraction(AiExtraction $extraction): array
    {
        $data = $extraction->result ?? [];
        $value = fn (string $key, int $max = 255): ?string => is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '' ? mb_substr(trim((string) $data[$key]), 0, $max) : null;
        $contact = (array) ($data['contact'] ?? []);
        $contactValue = fn (string $key): ?string => is_scalar($contact[$key] ?? null) && trim((string) $contact[$key]) !== '' ? mb_substr(trim((string) $contact[$key]), 0, 255) : null;

        $vatNumber = $value('vat_number', 32) ? ItalianVatNumber::normalize($value('vat_number', 32)) : null;
        $taxCode = $value('tax_code', 32) ? ItalianTaxCode::normalize($value('tax_code', 32)) : null;
        $warnings = array_values(array_filter((array) ($data['warnings'] ?? []), 'is_string'));

        $existing = $vatNumber || $taxCode
            ? Organization::withTrashed()->where(fn ($query) => $query
                ->when($vatNumber, fn ($query) => $query->orWhere('vat_number', $vatNumber))
                ->when($taxCode, fn ($query) => $query->orWhere('tax_code', $taxCode)))->first()
            : null;

        $website = $value('website');

        return [
            'defaults' => [
                'name' => $value('name') ?? '',
                'type' => OrganizationType::tryFrom((string) ($data['type'] ?? ''))?->value ?? OrganizationType::Company->value,
                'vat_number' => $vatNumber ?? '',
                'tax_code' => $taxCode ?? '',
                'sdi_code' => strtoupper((string) $value('sdi_code', 7)),
                'pec' => $value('pec') ?? '',
                'email' => $value('email') ?? '',
                'phone' => $value('phone', 32) ?? '',
                'website' => $website ?? '',
                'location' => [
                    'address' => $value('address') ?? '',
                    'postal_code' => $value('postal_code', 16) ?? '',
                    'city' => $value('city') ?? '',
                    'province' => strtoupper((string) $value('province', 8)),
                    'country' => strtoupper((string) $value('country', 2)) ?: 'IT',
                ],
                'contact' => [
                    'first_name' => $contactValue('first_name') ?? '',
                    'last_name' => $contactValue('last_name') ?? '',
                    'job_title' => $contactValue('job_title') ?? '',
                    'email' => $contactValue('email') ?? '',
                    'phone' => $contactValue('phone') ?? '',
                ],
            ],
            'review' => [
                'source' => $extraction->source,
                'model' => $extraction->model,
                'cost' => $extraction->cost !== null ? (float) $extraction->cost : null,
                'file_name' => $extraction->original_name,
                'file_url' => route('documents.file', $extraction),
                'file_mime' => $extraction->mime_type,
                'existing' => $existing ? ['id' => $existing->id, 'name' => $existing->name, 'role' => $existing->role->label()] : null,
                'warnings' => $warnings,
            ],
        ];
    }
}
