<?php

namespace App\Support;

use App\Models\VatRate;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Base of the document PDFs (resources/views/pdf/layout.blade.php): company data, embedded logo, DejaVu Sans.
 */
abstract class CompanyPdf
{
    public function __construct(protected Settings $settings) {}

    /**
     * @param  array<string, mixed>  $data
     */
    protected function render(string $view, array $data): DomPdf
    {
        return Pdf::loadView($view, [
            ...$data,
            'company' => $this->settings->group('company'),
            'logo' => $this->logoDataUri(),
        ])
            ->setPaper('a4')
            // Embed only the glyphs used: ~40 KB instead of ~1 MB per PDF.
            ->setOption(['isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Sans']);
    }

    /**
     * Legal references of the 0% VAT rates used by the lines (e.g. "Art. 10 DPR 633/72").
     *
     * @param  Collection<int, object>  $lines
     * @return Collection<int, string>
     */
    protected function legalReferences(Collection $lines): Collection
    {
        return VatRate::query()
            ->whereIn('id', $lines->whereNotNull('vat_nature')->pluck('vat_rate_id')->filter())
            ->whereNotNull('legal_reference')
            ->pluck('legal_reference')
            ->unique()
            ->values();
    }

    /**
     * The logo is embedded, so that the PDF renders without HTTP requests.
     */
    private function logoDataUri(): ?string
    {
        $path = $this->settings->get('company.logo_path');

        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';

        return "data:{$mime};base64,".base64_encode(Storage::disk('public')->get($path));
    }
}
