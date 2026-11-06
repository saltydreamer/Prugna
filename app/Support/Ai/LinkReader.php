<?php

namespace App\Support\Ai;

use App\Models\VatRate;
use App\Support\Fiscal\FiscalCodes;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads the price of a product (preventivatore) from its web page or from a screenshot of it.
 * Link: the page is downloaded by the server (public addresses only), the product data published
 * by the shop (JSON-LD, meta tags) and the visible text go to the AI. Screenshot: for the shops that
 * block automatic reading (Amazon) or show the user's own prices once logged in, the AI reads the
 * image. Either way the AI returns name and price; the cost follows the regime: VAT included where
 * it can't be deducted (flat-rate), excluded otherwise.
 */
class LinkReader
{
    private const MAX_BYTES = 3_000_000;

    private const MAX_TEXT = 15000;

    /**
     * Screenshots keep more pixels than phone photos: prices are small text on a wide page.
     */
    private const MAX_SCREENSHOT_SIDE = 2400;

    /**
     * Shops that always refuse automatic reading: no point in trying, the screenshot works.
     */
    private const BLOCKING_HOSTS = '/(^|\.)(amazon\.[a-z.]+|amzn\.[a-z]+|a\.co)$/i';

    public function __construct(private OpenRouterClient $ai, private Settings $settings) {}

    /**
     * @return array{description: string, unit_cost: float|null, note: string}
     */
    public function read(string $url): array
    {
        if (preg_match(self::BLOCKING_HOSTS, (string) parse_url($url, PHP_URL_HOST))) {
            throw new AiException(__('Amazon non permette la lettura automatica delle sue pagine: usa «Screenshot» (cattura la parte con nome e prezzo) o inserisci il costo a mano.'));
        }

        $this->guard($url);

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                    'Accept-Language' => 'it-IT,it;q=0.9,en;q=0.6',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->withOptions(['allow_redirects' => ['max' => 5, 'on_redirect' => fn ($request, $response, $uri) => $this->guard((string) $uri)]])
                ->get($url);
        } catch (ConnectionException) {
            throw new AiException(__('La pagina non risponde: inserisci il costo a mano.'));
        }

        if (! $response->successful()) {
            throw new AiException(__('Il sito ha risposto :status (alcuni negozi bloccano la lettura automatica): usa «Screenshot» o inserisci il costo a mano.', ['status' => $response->status()]));
        }

        $html = substr($response->body(), 0, self::MAX_BYTES);
        $title = $this->title($html);
        $structured = $this->structuredData($html);

        if (! $this->ai->isConfigured()) {
            if (($structured['price'] ?? null) === null) {
                throw new AiException(__('Il sito non pubblica il prezzo in modo leggibile e l\'AI non è attiva: inserisci il costo a mano.'));
            }

            return $this->result($structured['name'] ?? $title ?? $url, (float) $structured['price'], true, __('Prezzo pubblicato dal sito, IVA inclusa presunta.'), __('Letto dal sito'));
        }

        $text = implode("\n\n", array_filter([
            "URL: {$url}",
            $title ? "Titolo: {$title}" : null,
            $structured ? 'Dati prodotto pubblicati dal sito: '.json_encode($structured, JSON_UNESCAPED_UNICODE) : null,
            'Testo della pagina: '.Str::limit($this->visibleText($html), self::MAX_TEXT, '…'),
        ]));

        $data = $this->ai->structured($this->instructions(), 'product_price', $this->schema(), $text)['data'];

        return $this->fromAi($data, $title ?? $url, __('Letto dal sito'));
    }

    /**
     * Name and cost read by the AI from a screenshot of the product page (PNG, JPEG or WebP).
     *
     * @return array{description: string, unit_cost: float|null, note: string}
     */
    public function readScreenshot(string $contents, string $mimeType): array
    {
        if (! in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new AiException(__('Serve un\'immagine PNG, JPG o WebP.'));
        }

        if (! $this->ai->isConfigured()) {
            throw new AiException(__("La lettura con l'AI non è configurata: attivala in Impostazioni → AI."));
        }

        set_time_limit(120);
        [$contents, $mimeType] = DocumentReader::shrink($contents, $mimeType, self::MAX_SCREENSHOT_SIDE);

        $data = $this->ai->extract($this->instructions(), 'product_price', $this->schema(), $contents, $mimeType, 'screenshot')['data'];

        return $this->fromAi($data, __('Articolo'), __('Letto dallo screenshot'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{description: string, unit_cost: float, note: string}
     */
    private function fromAi(array $data, string $fallbackName, string $source): array
    {
        if (! ($data['found'] ?? false) || ($data['price'] ?? null) === null) {
            throw new AiException(__('Non ho trovato il prezzo: inserisci il costo a mano.').(filled($data['note'] ?? null) ? ' ('.$data['note'].')' : ''));
        }

        return $this->result(
            (string) (($data['name'] ?? '') ?: $fallbackName),
            (float) $data['price'],
            (bool) $data['vat_included'],
            trim((string) ($data['note'] ?? '')),
            $source,
        );
    }

    /**
     * @return array{description: string, unit_cost: float, note: string}
     */
    private function result(string $name, float $price, bool $vatIncluded, string $note, string $source): array
    {
        $vat = (float) (VatRate::query()->where('is_default', true)->value('rate') ?? 22);
        $vatFree = in_array((string) $this->settings->get('fiscal.regime'), FiscalCodes::VAT_FREE_REGIMES, true);

        // Flat-rate: the VAT paid is a cost. Otherwise it is deducted.
        $cost = match (true) {
            $vatFree && ! $vatIncluded => $price * (1 + $vat / 100),
            ! $vatFree && $vatIncluded => $price / (1 + $vat / 100),
            default => $price,
        };

        $read = __(':source: :price :vat.', [
            'source' => $source,
            'price' => number_format($price, 2, ',', '.').' €',
            'vat' => $vatIncluded ? __('IVA inclusa') : __('IVA esclusa'),
        ]);

        return [
            'description' => Str::limit(trim($name), 480, '…'),
            'unit_cost' => round($cost, 2),
            'note' => Str::limit(trim($read.' '.($vatFree ? __('Costo con IVA (non detraibile).') : __('Costo senza IVA.')).' '.$note), 250, '…'),
        ];
    }

    /**
     * Only public web addresses: never the server itself or the local network.
     */
    private function guard(string $url): void
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! $host) {
            throw new AiException(__('Indirizzo non valido: serve un link http o https.'));
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            throw new AiException(__('Il sito :host non esiste o non risponde.', ['host' => $host]));
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new AiException(__('Si possono leggere solo siti pubblici.'));
            }
        }
    }

    private function title(string $html): ?string
    {
        foreach (['/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i', '/<title[^>]*>(.*?)<\/title>/is'] as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5));
            }
        }

        return null;
    }

    /**
     * Product data published by the shop (JSON-LD Product/Offer, price meta tags).
     *
     * @return array{name?: string, price?: float, currency?: string}
     */
    private function structuredData(string $html): array
    {
        $data = [];

        if (preg_match_all('/<script[^>]+application\/ld\+json[^>]*>(.*?)<\/script>/is', $html, $scripts)) {
            foreach ($scripts[1] as $json) {
                $decoded = json_decode(html_entity_decode(trim($json)), true);
                $nodes = isset($decoded['@graph']) ? $decoded['@graph'] : (array_is_list((array) $decoded) ? (array) $decoded : [$decoded]);

                foreach ($nodes as $node) {
                    if (! is_array($node) || ! in_array('Product', (array) ($node['@type'] ?? []), true)) {
                        continue;
                    }

                    $offer = $node['offers'] ?? [];
                    $offer = isset($offer[0]) ? $offer[0] : $offer;
                    $price = $offer['price'] ?? $offer['lowPrice'] ?? null;

                    $data = array_filter([
                        'name' => isset($node['name']) ? (string) $node['name'] : null,
                        'price' => is_numeric($price) ? (float) $price : null,
                        'currency' => $offer['priceCurrency'] ?? null,
                    ], fn ($value) => $value !== null);

                    if (isset($data['price'])) {
                        return $data;
                    }
                }
            }
        }

        if (preg_match('/<meta[^>]+(?:property|itemprop)=["\'](?:product:price:amount|price)["\'][^>]+content=["\']([\d.,]+)/i', $html, $match)) {
            // "1.234,56" (Italian) or "1234.56".
            $data['price'] = (float) (str_contains($match[1], ',') ? str_replace(['.', ','], ['', '.'], $match[1]) : $match[1]);
        }

        return $data;
    }

    private function visibleText(string $html): string
    {
        $html = (string) preg_replace('/<(script|style|noscript|svg|nav|footer|header)\b[^>]*>.*?<\/\1>/is', ' ', $html);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    private function instructions(): string
    {
        return <<<'TXT'
            Sei un assistente che legge pagine di negozi online (testo della pagina o screenshot) per preparare un preventivo.
            Dalla pagina trova il prodotto principale e il suo prezzo di vendita attuale (quello da pagare, non il prezzo barrato).
            In uno screenshot ignora prodotti consigliati, sponsorizzati, accessori e offerte di altri venditori: leggi il prezzo del prodotto principale.
            - name: nome commerciale del prodotto, conciso (marca, modello, caratteristiche chiave).
            - price: prezzo unitario in euro come numero (es. 1234.5). Se la pagina usa un'altra valuta, convertilo in euro solo se il tasso è indicato, altrimenti found=false.
            - vat_included: true se il prezzo include l'IVA (tipico dei negozi per privati), false se è indicato "IVA esclusa" / "+IVA" / prezzo per aziende. Se la pagina mostra entrambi (es. Amazon Business), usa il prezzo IVA esclusa con vat_included=false.
            - found: false se non c'è un prodotto con prezzo chiaro (pagina di categoria, prezzo su richiesta, prodotto non disponibile senza prezzo).
            - note: una frase breve in italiano su cosa hai trovato (es. "Prezzo in offerta", "Più varianti: preso il modello base"); stringa vuota se non serve.
            Rispondi solo con i dati richiesti.
            TXT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['found', 'name', 'price', 'vat_included', 'note'],
            'properties' => [
                'found' => ['type' => 'boolean'],
                'name' => ['type' => 'string'],
                'price' => ['type' => ['number', 'null']],
                'vat_included' => ['type' => 'boolean'],
                'note' => ['type' => 'string'],
            ],
        ];
    }
}
