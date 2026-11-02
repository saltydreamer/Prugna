<?php

namespace App\Support\Ai;

/**
 * JSON schemas and instructions for reading documents. Every field is required and nullable
 * (strict structured outputs): the model returns null for what it can't find instead of guessing.
 */
class DocumentSchemas
{
    private const COMMON_RULES = <<<'TXT'
        Regole:
        - Non inventare: se un dato non è presente o non è leggibile usa null.
        - Date nel formato AAAA-MM-GG. Importi come numeri con il punto decimale, senza simbolo di valuta.
        - Partita IVA italiana: 11 cifre senza prefisso IT; partite IVA estere con il prefisso del paese. Codice fiscale in maiuscolo.
        - Paese come codice ISO a 2 lettere (IT se italiano). Provincia come sigla di 2 lettere.
        - In "warnings" elenca in italiano, brevemente, i dati incerti o le incongruenze (massimo 5).
        TXT;

    /**
     * @return array{name: string, instructions: string, schema: array<string, mixed>}
     */
    public static function expense(): array
    {
        return [
            'name' => 'expense_document',
            'instructions' => "Sei un assistente contabile italiano. Leggi il documento d'acquisto (fattura di un fornitore, ricevuta, scontrino o nota di credito) e restituisci i dati richiesti.\n"
                .self::COMMON_RULES."\n"
                ."- \"supplier\" è chi ha emesso il documento (il venditore), NON il destinatario.\n"
                ."- Righe: \"unit_price\" e \"total\" sono SENZA IVA. Se il documento riporta solo prezzi IVA inclusa (es. scontrini), scorpora l'IVA con l'aliquota indicata.\n"
                ."- \"vat_rate\" è la percentuale IVA (22, 10, 5, 4 o 0). Per righe senza IVA usa 0.\n"
                ."- Se le righe non sono leggibili, crea una sola riga riassuntiva per ogni aliquota.\n"
                ."- \"total\" è il totale del documento IVA inclusa; \"due_date\" la scadenza del pagamento se indicata.\n"
                .'- "payment_method": come viene pagato (contanti e carta per gli scontrini pagati al momento).',
            'schema' => self::object([
                'document_type' => ['type' => 'string', 'enum' => ['invoice', 'receipt', 'credit_note']],
                'supplier' => self::object([
                    'name' => self::nullable('string'),
                    'vat_number' => self::nullable('string'),
                    'tax_code' => self::nullable('string'),
                    'address' => self::nullable('string'),
                    'postal_code' => self::nullable('string'),
                    'city' => self::nullable('string'),
                    'province' => self::nullable('string'),
                    'country' => self::nullable('string'),
                    'email' => self::nullable('string'),
                    'pec' => self::nullable('string'),
                    'phone' => self::nullable('string'),
                ]),
                'number' => self::nullable('string'),
                'date' => self::nullable('string'),
                'due_date' => self::nullable('string'),
                'payment_method' => ['type' => ['string', 'null'], 'enum' => ['bank_transfer', 'card', 'cash', 'direct_debit', 'riba', 'other', null]],
                'description' => self::nullable('string'),
                'lines' => [
                    'type' => 'array',
                    'items' => self::object([
                        'description' => ['type' => 'string'],
                        'quantity' => ['type' => 'number'],
                        'unit_price' => ['type' => 'number'],
                        'vat_rate' => ['type' => 'number'],
                        'total' => ['type' => 'number'],
                    ]),
                ],
                'total_taxable' => self::nullable('number'),
                'total_vat' => self::nullable('number'),
                'total' => self::nullable('number'),
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]),
        ];
    }

    /**
     * @return array{name: string, instructions: string, schema: array<string, mixed>}
     */
    public static function organization(): array
    {
        return [
            'name' => 'organization_document',
            'instructions' => "Sei un assistente amministrativo italiano. Dal documento (biglietto da visita, carta intestata, visura camerale, firma email, fattura) ricava i dati anagrafici dell'azienda o del professionista principale e, se presente, della persona di riferimento.\n"
                .self::COMMON_RULES."\n"
                ."- \"sdi_code\": codice destinatario per la fatturazione elettronica (7 caratteri, 6 per la Pubblica Amministrazione).\n"
                .'- "type": company (società o ditta), freelancer (professionista), public_body (ente pubblico), non_profit (associazione), other.',
            'schema' => self::object([
                'name' => self::nullable('string'),
                'type' => ['type' => 'string', 'enum' => ['company', 'freelancer', 'public_body', 'non_profit', 'other']],
                'vat_number' => self::nullable('string'),
                'tax_code' => self::nullable('string'),
                'sdi_code' => self::nullable('string'),
                'pec' => self::nullable('string'),
                'email' => self::nullable('string'),
                'phone' => self::nullable('string'),
                'website' => self::nullable('string'),
                'address' => self::nullable('string'),
                'postal_code' => self::nullable('string'),
                'city' => self::nullable('string'),
                'province' => self::nullable('string'),
                'country' => self::nullable('string'),
                'contact' => self::object([
                    'first_name' => self::nullable('string'),
                    'last_name' => self::nullable('string'),
                    'job_title' => self::nullable('string'),
                    'email' => self::nullable('string'),
                    'phone' => self::nullable('string'),
                ]),
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{type: list<string>}
     */
    private static function nullable(string $type): array
    {
        return ['type' => [$type, 'null']];
    }
}
