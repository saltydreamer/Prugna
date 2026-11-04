<?php

namespace App\Support;

use App\Models\Intervention;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\Storage;

/**
 * Rapportino d'intervento (resources/views/pdf/intervention.blade.php): work done, time, travel,
 * materials, contract hours and the customer's signature. Internal notes are never printed.
 */
class InterventionPdf extends CompanyPdf
{
    public function make(Intervention $intervention): DomPdf
    {
        $intervention->loadMissing(['organization', 'location', 'contact', 'category', 'technician', 'materials', 'order', 'contract']);

        return $this->render('pdf.intervention', [
            'intervention' => $intervention,
            'start' => $intervention->localStart(),
            'end' => $intervention->endsAt()?->copy()->setTimezone(config('app.display_timezone')),
            'signedAt' => $intervention->signed_at?->copy()->setTimezone(config('app.display_timezone')),
            'signature' => $this->signatureDataUri($intervention),
            'contractRemaining' => $intervention->contract?->remainingMinutes(),
            'customer' => CustomerSnapshot::from($intervention->organization),
        ]);
    }

    public function filename(Intervention $intervention): string
    {
        return "Rapportino-{$intervention->number}.pdf";
    }

    private function signatureDataUri(Intervention $intervention): ?string
    {
        if (! $intervention->signature_path || ! Storage::disk('local')->exists($intervention->signature_path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($intervention->signature_path));
    }
}
