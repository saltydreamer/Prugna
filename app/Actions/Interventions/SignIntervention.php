<?php

namespace App\Actions\Interventions;

use App\Models\Intervention;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Stores the customer's signature (PNG data URL drawn on the device): the intervention becomes final.
 */
class SignIntervention
{
    /** Largest accepted signature image, in bytes once decoded. */
    public const MAX_BYTES = 512 * 1024;

    public const PREFIX = 'data:image/png;base64,';

    /**
     * @param  string  $field  Validation key for the errors ("signature", "signature.data").
     */
    public function handle(Intervention $intervention, string $signerName, string $dataUrl, string $field = 'signature'): void
    {
        $png = str_starts_with($dataUrl, self::PREFIX) ? base64_decode(substr($dataUrl, strlen(self::PREFIX)), true) : false;

        if ($png === false || strlen($png) > self::MAX_BYTES || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw ValidationException::withMessages([$field => __('La firma non è valida: riprova.')]);
        }

        $path = "interventions/signatures/{$intervention->id}-".now()->format('YmdHis').'.png';
        Storage::disk('local')->put($path, $png);

        $intervention->forceFill([
            'signature_path' => $path,
            'signed_by_name' => $signerName,
            'signed_at' => now(),
        ])->saveQuietly();
    }
}
