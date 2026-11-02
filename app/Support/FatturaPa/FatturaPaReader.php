<?php

namespace App\Support\FatturaPa;

use App\Support\Ai\AiException;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads a received FatturaPA electronic invoice (XML, or signed .p7m) into the same structure
 * produced by the AI for expenses (App\Support\Ai\DocumentSchemas::expense()). No AI involved.
 */
class FatturaPaReader
{
    /**
     * Whether the file looks like a FatturaPA (plain or signed).
     */
    public static function looks(string $contents, string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, ['xml', 'p7m'], true) || str_contains(substr($contents, 0, 4096), 'FatturaElettronica');
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $contents): array
    {
        $xml = $this->unwrap($contents);
        $document = new DOMDocument;

        if (! @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new AiException(__('Il file non è una fattura elettronica XML leggibile.'));
        }

        $xpath = new DOMXPath($document);
        $root = $xpath->query('/*')->item(0);

        if (! $root instanceof DOMElement || $root->localName !== 'FatturaElettronica') {
            throw new AiException(__('Il file XML non è una FatturaPA.'));
        }

        $value = fn (string $path, ?DOMElement $context = null): ?string => $this->value($xpath, $path, $context);
        $supplier = $xpath->query('//*[local-name()="CedentePrestatore"]')->item(0);
        $body = $xpath->query('//*[local-name()="FatturaElettronicaBody"]')->item(0);

        if (! $supplier instanceof DOMElement || ! $body instanceof DOMElement) {
            throw new AiException(__('Nella fattura mancano il fornitore o il corpo del documento.'));
        }

        $vatCountry = $value('.//*[local-name()="IdFiscaleIVA"]/*[local-name()="IdPaese"]', $supplier);
        $vatCode = $value('.//*[local-name()="IdFiscaleIVA"]/*[local-name()="IdCodice"]', $supplier);
        $name = $value('.//*[local-name()="Anagrafica"]/*[local-name()="Denominazione"]', $supplier)
            ?? trim(($value('.//*[local-name()="Anagrafica"]/*[local-name()="Nome"]', $supplier) ?? '').' '.($value('.//*[local-name()="Anagrafica"]/*[local-name()="Cognome"]', $supplier) ?? ''));

        $documentType = $value('.//*[local-name()="DatiGeneraliDocumento"]/*[local-name()="TipoDocumento"]', $body);

        $lines = [];

        foreach ($xpath->query('.//*[local-name()="DettaglioLinee"]', $body) as $line) {
            $quantity = (float) ($value('./*[local-name()="Quantita"]', $line) ?? 1) ?: 1.0;
            $total = (float) ($value('./*[local-name()="PrezzoTotale"]', $line) ?? 0);

            $lines[] = [
                'description' => (string) $value('./*[local-name()="Descrizione"]', $line),
                'quantity' => $quantity,
                // Discounts are already applied in PrezzoTotale: the unit price is derived from it.
                'unit_price' => round($total / $quantity, 4),
                'vat_rate' => (float) ($value('./*[local-name()="AliquotaIVA"]', $line) ?? 0),
                'vat_nature' => $value('./*[local-name()="Natura"]', $line),
                'total' => $total,
            ];
        }

        $taxable = 0.0;
        $vat = 0.0;

        foreach ($xpath->query('.//*[local-name()="DatiRiepilogo"]', $body) as $summary) {
            $taxable += (float) $value('./*[local-name()="ImponibileImporto"]', $summary);
            $vat += (float) $value('./*[local-name()="Imposta"]', $summary);
        }

        $documentTotal = $value('.//*[local-name()="ImportoTotaleDocumento"]', $body);
        $payment = $xpath->query('.//*[local-name()="DettaglioPagamento"]', $body)->item(0);

        return [
            'document_type' => $documentType === 'TD04' ? 'credit_note' : 'invoice',
            'supplier' => [
                'name' => $name ?: null,
                'vat_number' => $vatCode ? ($vatCountry && $vatCountry !== 'IT' ? $vatCountry.$vatCode : $vatCode) : null,
                'tax_code' => $value('.//*[local-name()="CodiceFiscale"]', $supplier),
                'address' => trim(($value('.//*[local-name()="Sede"]/*[local-name()="Indirizzo"]', $supplier) ?? '').' '.($value('.//*[local-name()="Sede"]/*[local-name()="NumeroCivico"]', $supplier) ?? '')) ?: null,
                'postal_code' => $value('.//*[local-name()="Sede"]/*[local-name()="CAP"]', $supplier),
                'city' => $value('.//*[local-name()="Sede"]/*[local-name()="Comune"]', $supplier),
                'province' => $value('.//*[local-name()="Sede"]/*[local-name()="Provincia"]', $supplier),
                'country' => $value('.//*[local-name()="Sede"]/*[local-name()="Nazione"]', $supplier),
                'email' => $value('.//*[local-name()="Contatti"]/*[local-name()="Email"]', $supplier),
                'pec' => null,
                'phone' => $value('.//*[local-name()="Contatti"]/*[local-name()="Telefono"]', $supplier),
            ],
            'number' => $value('.//*[local-name()="DatiGeneraliDocumento"]/*[local-name()="Numero"]', $body),
            'date' => $value('.//*[local-name()="DatiGeneraliDocumento"]/*[local-name()="Data"]', $body),
            'due_date' => $payment instanceof DOMElement ? $value('./*[local-name()="DataScadenzaPagamento"]', $payment) : null,
            'payment_method' => $payment instanceof DOMElement ? $value('./*[local-name()="ModalitaPagamento"]', $payment) : null,
            'description' => $value('.//*[local-name()="DatiGeneraliDocumento"]/*[local-name()="Causale"]', $body),
            'lines' => $lines,
            'total_taxable' => round($taxable, 2),
            'total_vat' => round($vat, 2),
            'total' => $documentTotal !== null ? (float) $documentTotal : round($taxable + $vat, 2),
            'warnings' => $xpath->query('//*[local-name()="FatturaElettronicaBody"]')->length > 1
                ? [__('Il file contiene più fatture: è stata importata solo la prima.')]
                : [],
        ];
    }

    /**
     * Extracts the XML from a signed .p7m (binary or base64 encoded) by locating the document in it.
     */
    private function unwrap(string $contents): string
    {
        if (! str_contains($contents, 'FatturaElettronica') && ($decoded = base64_decode(preg_replace('/\s+/', '', $contents), true)) !== false) {
            $contents = $decoded;
        }

        $start = strpos($contents, '<?xml');
        $start = $start === false ? (preg_match('/<(\w+:)?FatturaElettronica[\s>]/', $contents, $match, PREG_OFFSET_CAPTURE) ? $match[0][1] : false) : $start;

        if ($start === false || ! preg_match('/<\/(\w+:)?FatturaElettronica>/', $contents, $end, PREG_OFFSET_CAPTURE, $start)) {
            throw new AiException(__('Il file non contiene una fattura elettronica.'));
        }

        return substr($contents, $start, $end[0][1] + strlen($end[0][0]) - $start);
    }

    private function value(DOMXPath $xpath, string $path, ?DOMElement $context = null): ?string
    {
        $node = $xpath->query($path, $context)?->item(0);
        $text = $node ? trim($node->textContent) : '';

        return $text === '' ? null : $text;
    }
}
