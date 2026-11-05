<?php

namespace App\Support\Dolibarr;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use stdClass;

/**
 * Read access to a Dolibarr backup loaded by SqlDumpReader into a SQLite staging database,
 * with the conversions shared by the import steps (texts, dates, amounts, dictionaries).
 */
class DolibarrSource
{
    public const CONNECTION = 'dolibarr_staging';

    /** Tables read from the backup. */
    public const TABLES = [
        'const', 'user', 'societe', 'societe_extrafields', 'socpeople',
        'c_country', 'c_departements', 'c_typent', 'c_payment_term', 'c_paiement', 'c_units',
        'categorie', 'categorie_product', 'product', 'product_customer_price',
        'propal', 'propaldet', 'commande', 'commandedet', 'facture', 'facturedet', 'paiement', 'paiement_facture',
        'commande_fournisseur', 'commande_fournisseurdet', 'facture_fourn', 'facture_fourn_det', 'paiementfourn', 'paiementfourn_facturefourn',
        'fichinter', 'fichinterdet', 'contrat', 'contratdet', 'element_element',
    ];

    /** Foreign keys used to read lines and payments document by document. */
    private const INDEXES = [
        'propaldet' => 'fk_propal', 'commandedet' => 'fk_commande', 'facturedet' => 'fk_facture',
        'commande_fournisseurdet' => 'fk_commande', 'facture_fourn_det' => 'fk_facture_fourn',
        'fichinterdet' => 'fk_fichinter', 'contratdet' => 'fk_contrat', 'socpeople' => 'fk_soc',
        'paiement_facture' => 'fk_facture', 'paiementfourn_facturefourn' => 'fk_facturefourn',
        'categorie_product' => 'fk_product', 'societe_extrafields' => 'fk_object',
    ];

    /** @var array<string, array<int|string, stdClass>> */
    private array $dictionaries = [];

    /** @var array<string, bool> */
    private array $tables = [];

    public function __construct(string $stagingPath)
    {
        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => $stagingPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        DB::purge(self::CONNECTION);

        foreach ($this->connection()->select("SELECT name FROM sqlite_master WHERE type = 'table'") as $table) {
            $this->tables[$table->name] = true;
        }

        foreach (self::INDEXES as $table => $column) {
            if ($this->hasColumn($table, $column)) {
                $this->connection()->statement("CREATE INDEX IF NOT EXISTS \"{$table}_{$column}\" ON \"{$table}\" (\"{$column}\")");
            }
        }
    }

    public function connection(): Connection
    {
        return DB::connection(self::CONNECTION);
    }

    public function has(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->has($table) && in_array($column, $this->columns($table), true);
    }

    /**
     * @return list<string>
     */
    public function columns(string $table): array
    {
        return $this->has($table) ? array_column($this->connection()->select("PRAGMA table_info(\"{$table}\")"), 'name') : [];
    }

    /**
     * Rows of a table ordered by their id (empty when the table is not in the backup).
     *
     * @return Collection<int, stdClass>
     */
    public function rows(string $table, string $orderBy = 'rowid'): Collection
    {
        if (! $this->has($table)) {
            return collect();
        }

        $query = $this->connection()->table($table);

        if ($this->hasColumn($table, $orderBy)) {
            $query->orderBy($orderBy);
        }

        return $query->get();
    }

    /**
     * Rows of a table read in chunks (documents: the backup may be large), optionally filtered.
     *
     * @param  (callable(Builder): mixed)|null  $constraint
     * @return LazyCollection<int, stdClass>
     */
    public function each(string $table, ?callable $constraint = null): LazyCollection
    {
        if (! $this->has($table)) {
            return LazyCollection::empty();
        }

        $query = $this->connection()->table($table)->orderBy('rowid');

        if ($constraint) {
            $constraint($query);
        }

        return $query->lazy(500);
    }

    /**
     * Rows referring to a parent row (lines of a document, payments of an invoice...).
     *
     * @return Collection<int, stdClass>
     */
    public function children(string $table, string $key, int $id): Collection
    {
        return $this->has($table) ? $this->connection()->table($table)->where($key, $id)->orderBy('rowid')->get() : collect();
    }

    /**
     * A dictionary row (country, province, payment term...) by id.
     */
    public function dictionary(string $table, mixed $id, string $key = 'rowid'): ?stdClass
    {
        if ($id === null || $id === '' || ! $this->has($table)) {
            return null;
        }

        $this->dictionaries[$table] ??= $this->rows($table, $key)->keyBy($key)->all();

        return $this->dictionaries[$table][$id] ?? null;
    }

    /**
     * A value of the global Dolibarr configuration (llx_const).
     */
    public function constant(string $name): ?string
    {
        if (! $this->has('const')) {
            return null;
        }

        $value = $this->connection()->table('const')->where('name', $name)->orderBy('entity')->value('value');

        return $value === null ? null : (string) $value;
    }

    /**
     * Links between documents (llx_element_element) as [source id => [target ids]].
     *
     * @return array<int, list<int>>
     */
    public function links(string $sourceType, string $targetType): array
    {
        if (! $this->has('element_element')) {
            return [];
        }

        $links = [];

        foreach ($this->connection()->table('element_element')->where('sourcetype', $sourceType)->where('targettype', $targetType)->orderBy('rowid')->get() as $link) {
            $links[(int) $link->fk_source][] = (int) $link->fk_target;
        }

        return $links;
    }

    /**
     * Documents linked in llx_element_element in either direction, as [id of $to => [ids of $from]]
     * (e.g. related('propal', 'commande') gives the proposals of each order).
     *
     * @return array<int, list<int>>
     */
    public function related(string $from, string $to): array
    {
        $related = [];

        foreach ($this->links($from, $to) as $fromId => $toIds) {
            foreach ($toIds as $toId) {
                $related[$toId][] = $fromId;
            }
        }

        foreach ($this->links($to, $from) as $toId => $fromIds) {
            foreach ($fromIds as $fromId) {
                $related[$toId][] = $fromId;
            }
        }

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $related);
    }

    /**
     * Plain text from a Dolibarr text: descriptions and notes may contain HTML (WYSIWYG editor).
     */
    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;

        if ($text !== strip_tags($text) || str_contains($text, '&')) {
            $text = (string) preg_replace('/<br\s*\/?>|<\/(p|div|li|tr|h\d)>/i', "\n", $text);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = str_replace("\u{00A0}", ' ', $text);
        }

        $text = (string) preg_replace("/[ \t]+\n/", "\n", str_replace("\r", '', $text));
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));

        return $text === '' ? null : $text;
    }

    /**
     * Short single-line text, cut to a column length.
     */
    public static function line(mixed $value, int $length = 255): ?string
    {
        $text = self::text($value);

        return $text === null ? null : mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, $length);
    }

    /**
     * A date column ("2026-03-15" or "2026-03-15 10:20:00"); empty and zero dates become null.
     */
    public static function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, config('app.display_timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Decimal amount as a string with a fixed number of decimals (no float noise).
     */
    public static function amount(mixed $value, int $decimals = 2): string
    {
        return number_format(round((float) ($value ?? 0), $decimals), $decimals, '.', '');
    }

    public static function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Joins texts with a blank line, skipping empty ones.
     */
    public static function join(?string ...$texts): ?string
    {
        $texts = array_filter($texts, fn (?string $text) => $text !== null && trim($text) !== '');

        return $texts ? implode("\n\n", $texts) : null;
    }
}
