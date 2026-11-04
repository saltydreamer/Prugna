<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Key/value settings editable from the UI, cached forever and flushed on save.
 *
 *   app(Settings::class)->get('company.name');
 *   app(Settings::class)->set(['company.name' => 'Acme']);
 */
class Settings
{
    private const CACHE_KEY = 'app.settings';

    /**
     * Values stored encrypted in the database.
     */
    private const ENCRYPTED = ['mail.password', 'ai.api_key', 'sdi.api_key', 'sdi.token', 'sdi.webhook_secret', 'push.vapid_private_key'];

    /**
     * Defaults used until a value is saved.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'company.country' => 'IT',
        'mail.enabled' => false,
        'mail.port' => 587,
        'mail.encryption' => 'tls',
        'fiscal.regime' => 'RF01',
        'fiscal.withholding_enabled' => false,
        'fiscal.withholding_type' => 'RT01',
        'fiscal.withholding_rate' => 20,
        'fiscal.withholding_reason' => 'A',
        'fiscal.pension_enabled' => false,
        'fiscal.pension_type' => 'TC22',
        'fiscal.pension_rate' => 4,
        'fiscal.pension_vat_rate_id' => null,
        'fiscal.pension_withholding' => false,
        'fiscal.stamp_duty_enabled' => true,
        'fiscal.stamp_duty_charge_customer' => true,
        'fiscal.invoice_notes' => null,
        'ai.enabled' => false,
        'ai.model' => null,
        'ai.privacy' => 'no_training',
        'sdi.enabled' => false,
        'sdi.environment' => 'test',
        'sdi.auto_send' => true,
        // € per call (VAT excluded) and free calls per day, from the Openapi price list (base prices):
        // editable in Impostazioni → Fatturazione elettronica.
        'sdi.prices' => [
            'POST /invoices' => ['price' => 0.07, 'free' => 0],
            'GET /invoices_download/{id}' => ['price' => 0.001, 'free' => 0],
            'GET /invoices' => ['price' => 0.001, 'free' => 1000],
            'GET /invoices/{id}' => ['price' => 0.001, 'free' => 1000],
            'GET /invoices_notifications/{id}' => ['price' => 0.001, 'free' => 1000],
            'POST /business_registry_configurations' => ['price' => 0.001, 'free' => 100],
            'GET /business_registry_configurations' => ['price' => 0.001, 'free' => 1000],
            'POST /api_configurations' => ['price' => 0.001, 'free' => 400],
            'GET /api_configurations' => ['price' => 0.001, 'free' => 1000],
            'DELETE /api_configurations/{id}' => ['price' => 0.001, 'free' => 50],
        ],
        'interventions.rounding_minutes' => 30,
        'interventions.callout_product_id' => null,
        'interventions.km_product_id' => null,
        'interventions.travel_product_id' => null,
        'quotes.validity_days' => 30,
        'quotes.terms' => "Prezzi IVA esclusa.\nL'offerta è valida fino alla data indicata.",
        'quotes.email_subject' => 'Preventivo {numero} – {azienda}',
        'quotes.email_body' => "Gentile {cliente},\n\nin allegato trova il nostro preventivo {numero}.\nPuò consultarlo e accettarlo online a questo link:\n{link}\n\nRestiamo a disposizione per qualsiasi chiarimento.\n\nCordiali saluti,\n{mittente}\n{azienda}",
    ];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    /**
     * All the settings of a group, e.g. "company" → ['name' => ..., 'city' => ...].
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $values = [];

        foreach ([...self::DEFAULTS, ...$this->all()] as $key => $value) {
            if (str_starts_with($key, "{$group}.")) {
                $values[substr($key, strlen($group) + 1)] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (in_array($key, self::ENCRYPTED, true) && $value !== null && $value !== '') {
                $value = Crypt::encryptString((string) $value);
            }

            Setting::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        }

        $this->flush();
    }

    public function flush(): void
    {
        $this->values = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());
        } catch (Throwable) {
            // No database yet (e.g. during the Docker build): fall back to defaults.
            return [];
        }

        $values = [];

        foreach ($stored as $key => $json) {
            $value = json_decode((string) $json, true);

            if (in_array($key, self::ENCRYPTED, true) && is_string($value) && $value !== '') {
                try {
                    $value = Crypt::decryptString($value);
                } catch (Throwable) {
                    $value = null;
                }
            }

            $values[$key] = $value;
        }

        return $this->values = $values;
    }

    /**
     * True when a value has been saved for the key (e.g. the SMTP password).
     */
    public function has(string $key): bool
    {
        return Arr::exists($this->all(), $key) && $this->all()[$key] !== null && $this->all()[$key] !== '';
    }
}
