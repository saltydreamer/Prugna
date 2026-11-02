<?php

namespace App\Support;

use App\Actions\Quotes\QuoteTotals;
use App\Models\Quote;
use App\Models\VatRate;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\Storage;

/**
 * PDF of a quote (resources/views/pdf/quote.blade.php).
 */
class QuotePdf
{
    public function __construct(private Settings $settings) {}

    public function make(Quote $quote): DomPdf
    {
        $quote->loadMissing(['lines', 'contact', 'owner']);

        $legalReferences = VatRate::query()
            ->whereIn('id', $quote->lines->whereNotNull('vat_nature')->pluck('vat_rate_id')->filter())
            ->whereNotNull('legal_reference')
            ->pluck('legal_reference')
            ->unique()
            ->values();

        return Pdf::loadView('pdf.quote', [
            'quote' => $quote,
            'lines' => $quote->lines,
            'totals' => QuoteTotals::summarize($quote->lines),
            'company' => $this->settings->group('company'),
            'logo' => $this->logoDataUri(),
            'legalReferences' => $legalReferences,
        ])
            ->setPaper('a4')
            // Embed only the glyphs used: ~40 KB instead of ~1 MB per PDF.
            ->setOption(['isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Sans']);
    }

    public function filename(Quote $quote): string
    {
        return "Preventivo-{$quote->number}.pdf";
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
