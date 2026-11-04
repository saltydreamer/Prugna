<?php

namespace App\Http\Requests\Interventions;

use App\Enums\InterventionMode;
use App\Enums\Permission;
use App\Models\Intervention;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InterventionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $intervention = $this->route('intervention');

        return $intervention instanceof Intervention
            ? $this->user()->can('update', $intervention)
            : $this->user()->can('create', Intervention::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $intervention = $this->route('intervention');
        $current = $intervention instanceof Intervention ? $intervention : null;
        $organizationId = $this->input('organization_id');

        return [
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')->where('role', 'customer')->where(
                fn ($query) => $query->whereNull('deleted_at')->when($current?->organization_id, fn ($query, int $id) => $query->orWhere('id', $id)),
            )],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('organization_id', $organizationId)],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('organization_id', $organizationId)],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->where('organization_id', $organizationId)],
            'category_id' => ['required', 'integer', Rule::exists('intervention_categories', 'id')->where(
                fn ($query) => $query->where('is_active', true)->when($current?->category_id, fn ($query, int $id) => $query->orWhere('id', $id)),
            )],
            'mode' => ['required', Rule::enum(InterventionMode::class)],
            'technician_id' => $this->user()->can(Permission::InterventionsSupervise->value)
                ? ['nullable', 'integer', Rule::exists('users', 'id')]
                : ['exclude'],
            'started_at' => ['required', 'date'],
            // A running timer keeps going until "Termina".
            'duration_minutes' => [$current?->isRunning() ? 'nullable' : 'required', 'integer', 'between:1,1440'],
            'description' => ['nullable', 'string', 'max:10000'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
            'billable' => ['boolean'],
            'callout' => ['boolean'],
            'km' => ['nullable', 'numeric', 'between:0,99999'],
            'travel_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'materials' => ['nullable', 'array', 'max:100'],
            'materials.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'materials.*.code' => ['nullable', 'string', 'max:64'],
            'materials.*.description' => ['required', 'string', 'max:1000'],
            'materials.*.unit' => ['nullable', 'string', 'max:16'],
            'materials.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'materials.*.unit_price' => ['required', 'numeric', 'between:0,99999999'],
            'materials.*.vat_rate_id' => ['nullable', 'integer', Rule::exists('vat_rates', 'id')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key === null) {
            $data['km'] = $data['km'] ?? 0;
            $data['travel_minutes'] = $data['travel_minutes'] ?? 0;
            $data['materials'] ??= [];
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organization_id' => 'cliente',
            'location_id' => 'sede',
            'contact_id' => 'referente',
            'order_id' => 'ordine',
            'category_id' => 'categoria',
            'mode' => 'modalità',
            'technician_id' => 'tecnico',
            'started_at' => 'inizio',
            'duration_minutes' => 'durata',
            'description' => 'descrizione',
            'km' => 'km',
            'travel_minutes' => 'tempo di viaggio',
            'materials.*.description' => 'descrizione del materiale',
            'materials.*.quantity' => 'quantità',
            'materials.*.unit_price' => 'prezzo',
        ];
    }
}
