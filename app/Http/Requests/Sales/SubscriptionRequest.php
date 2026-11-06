<?php

namespace App\Http\Requests\Sales;

use App\Enums\BillingPeriod;
use App\Enums\QuoteLineType;
use App\Http\Requests\Concerns\ValidatesDocumentLines;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubscriptionRequest extends FormRequest
{
    use ValidatesDocumentLines;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $subscription = $this->route('subscription');

        return $subscription instanceof Subscription
            ? $this->user()->can('update', $subscription)
            : $this->user()->can('create', Subscription::class);
    }

    /**
     * Customer, start and period are frozen once a fee is invoiced: the periods are aligned to them.
     */
    public function scheduleEditable(): bool
    {
        $subscription = $this->route('subscription');

        return ! $subscription instanceof Subscription || ! $subscription->hasBilledPeriods();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $schedule = $this->scheduleEditable() ? [
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')->whereNull('deleted_at')->where('role', 'customer')],
            'billing_period' => ['required', Rule::enum(BillingPeriod::class)],
            'starts_on' => ['required', 'date'],
            'billed_until' => ['nullable', 'date'],
        ] : [
            'organization_id' => ['exclude'],
            'billing_period' => ['exclude'],
            'starts_on' => ['exclude'],
            'billed_until' => ['exclude'],
        ];

        return [
            ...$schedule,
            'title' => ['required', 'string', 'max:255'],
            'billing_mode' => ['required', Rule::in([Subscription::MODE_ADVANCE, Subscription::MODE_ARREARS])],
            'ends_on' => ['nullable', 'date', $this->scheduleEditable() ? 'after_or_equal:starts_on' : 'after_or_equal:'.$this->route('subscription')->starts_on->toDateString()],
            'payment_term_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            ...$this->lineRules(),
            // Only services: headings and free text would repeat on every fee.
            'lines.*.type' => ['required', Rule::in([QuoteLineType::Item->value])],
            'lines.*.unit_price' => ['required', 'numeric', 'between:0,99999999'],
        ];
    }

    /**
     * Fees invoiced elsewhere up to billed_until: Prugna resumes the day after, so the contract
     * must have started by then.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->scheduleEditable() || ! $this->filled('billed_until') || ! $this->filled('starts_on')
                || $validator->errors()->hasAny(['starts_on', 'billed_until'])) {
                return;
            }

            $resume = CarbonImmutable::parse($this->input('billed_until'))->addDay();

            if (CarbonImmutable::parse($this->input('starts_on'))->greaterThan($resume)) {
                $validator->errors()->add('starts_on', __('Con i canoni fatturati fino al :date la decorrenza non può essere dopo il :resume: Prugna fattura dal giorno dopo.', [
                    'date' => CarbonImmutable::parse($this->input('billed_until'))->format('d/m/Y'),
                    'resume' => $resume->format('d/m/Y'),
                ]));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organization_id' => 'cliente',
            'title' => 'descrizione',
            'billing_period' => 'periodicità',
            'billing_mode' => 'fatturazione',
            'starts_on' => 'decorrenza',
            'ends_on' => 'scadenza',
            'billed_until' => 'già fatturato fino al',
            'payment_term_id' => 'condizione di pagamento',
            'owner_id' => 'responsabile',
            ...$this->lineAttributes(),
        ];
    }
}
