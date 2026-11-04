<?php

namespace App\Http\Requests\Interventions;

use App\Actions\Interventions\SignIntervention;
use App\Models\Intervention;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An intervention recorded on the device ("Interventi sul campo"), possibly offline: same rules as the form,
 * plus the device id, an optional signature and materials priced by the server when no price is given.
 */
class SyncInterventionRequest extends InterventionRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Intervention::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'client_uuid' => ['required', 'uuid'],
            'duration_minutes' => ['required', 'integer', 'between:1,1440'],
            'technician_id' => ['exclude'],
            'materials.*.unit_price' => ['nullable', 'numeric', 'between:0,99999999'],
            'signature' => ['nullable', 'array'],
            'signature.name' => ['required_with:signature', 'string', 'max:120'],
            'signature.data' => ['required_with:signature', 'string', 'starts_with:'.SignIntervention::PREFIX, 'max:'.(int) (SignIntervention::MAX_BYTES * 1.4)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), 'signature.name' => 'nome di chi firma', 'signature.data' => 'firma'];
    }
}
