<?php

namespace App\Support\Dolibarr;

use PDO;
use PDOStatement;
use RuntimeException;
use ZipArchive;

/**
 * Reads a Dolibarr database backup (mysqldump output, as made by Dolibarr's "Backup" tool:
 * .sql, .sql.gz or .zip) into a SQLite staging database.
 *
 * Only the CREATE TABLE and INSERT statements of the requested tables are parsed: nothing in
 * the backup is ever executed, so it can't touch Prugna's own database. Integers become
 * integers, decimals and strings stay strings (no float rounding), NULL stays null.
 */
class SqlDumpReader
{
    private const BATCH = 1000;

    private PDO $staging;

    /** @var array<string, list<string>> Columns of the requested tables, by table name without prefix. */
    private array $columns = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, int> Rows of tables that are in the backup but not requested (only counted). */
    private array $other = [];

    private ?string $prefix = null;

    private int $skipped = 0;

    private int $pending = 0;

    /** @var array<string, PDOStatement> */
    private array $inserts = [];

    private ?ZipArchive $zip = null;

    /**
     * @param  list<string>  $tables  Table names without the prefix ("societe", "c_country"...).
     * @return array{prefix: ?string, tables: array<string, int>, other: array<string, int>, skipped_rows: int}
     */
    public function load(string $path, string $stagingPath, array $tables): array
    {
        $wanted = array_flip($tables);

        @unlink($stagingPath);
        $this->staging = new PDO('sqlite:'.$stagingPath);
        $this->staging->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->staging->exec('PRAGMA journal_mode = OFF; PRAGMA synchronous = OFF;');
        $this->staging->beginTransaction();

        $buffer = '';

        foreach ($this->lines($path) as $line) {
            if ($buffer === '') {
                $start = ltrim($line);

                if ($start === '' || str_starts_with($start, '--') || str_starts_with($start, '#')) {
                    continue;
                }
            }

            $buffer .= $line;

            if (! str_ends_with(rtrim($line), ';') || ! self::outsideString($buffer)) {
                continue;
            }

            $this->statement($buffer, $wanted);
            $buffer = '';
        }

        $this->staging->commit();

        if (! isset($this->columns['societe'])) {
            throw new RuntimeException(__('Il file non sembra un backup del database di Dolibarr: manca la tabella delle terze parti.'));
        }

        ksort($this->counts);
        arsort($this->other);

        return ['prefix' => $this->prefix, 'tables' => $this->counts, 'other' => $this->other, 'skipped_rows' => $this->skipped];
    }

    /**
     * @param  array<string, int>  $wanted
     */
    private function statement(string $sql, array $wanted): void
    {
        if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\(/i', $sql, $match)) {
            $table = $this->tableKey($match[1]);

            if ($table !== null && isset($wanted[$table]) && ! isset($this->columns[$table])) {
                preg_match_all('/^\s*`([^`]+)`\s+\w/m', $sql, $columns);
                $this->createTable($table, $columns[1]);
            }

            return;
        }

        if (! preg_match('/^\s*(?:INSERT|REPLACE)\s+(?:\w+\s+)*?INTO\s+`?([A-Za-z0-9_]+)`?\s*(?:\(([^)]*)\)\s*)?VALUES\s*/i', $sql, $match)) {
            return;
        }

        $table = $this->tableKey($match[1]);

        if ($table === null) {
            return;
        }

        if (! isset($wanted[$table])) {
            $this->other[$table] = ($this->other[$table] ?? 0) + substr_count($sql, '),(') + 1;

            return;
        }

        $columns = isset($match[2]) && trim($match[2]) !== ''
            ? array_map(fn (string $column) => trim($column, " `\t\r\n"), explode(',', $match[2]))
            : ($this->columns[$table] ?? null);

        if ($columns === null) {
            // INSERT without column names and without the CREATE TABLE: positions can't be mapped.
            $this->skipped += substr_count($sql, '),(') + 1;

            return;
        }

        if (! isset($this->columns[$table])) {
            $this->createTable($table, $columns);
        }

        foreach ($this->rows(substr($sql, strlen($match[0]))) as $values) {
            if (count($values) !== count($columns)) {
                $this->skipped++;

                continue;
            }

            $this->insert($table, array_combine($columns, $values));
        }
    }

    /**
     * Table name without the Dolibarr prefix (default "llx_", taken from the first table), or null for other tables.
     */
    private function tableKey(string $name): ?string
    {
        if ($this->prefix === null) {
            if (! preg_match('/^([A-Za-z0-9]+_)/', $name, $match)) {
                return null;
            }

            $this->prefix = $match[1];
        }

        return str_starts_with($name, $this->prefix) ? strtolower(substr($name, strlen($this->prefix))) : null;
    }

    /**
     * @param  list<string>  $columns
     */
    private function createTable(string $table, array $columns): void
    {
        $this->columns[$table] = $columns;
        $this->counts[$table] ??= 0;

        // No column types: values keep the type they had in the dump (integer, text, null).
        $definition = implode(', ', array_map(fn (string $column) => '"'.str_replace('"', '""', $column).'"', $columns));
        $this->staging->exec("CREATE TABLE \"{$table}\" ({$definition})");
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys(array_intersect_key($row, array_flip($this->columns[$table])));
        $key = $table.'|'.implode(',', $columns);

        if (! isset($this->inserts[$key])) {
            $names = implode(', ', array_map(fn (string $column) => '"'.str_replace('"', '""', $column).'"', $columns));
            $marks = implode(', ', array_fill(0, count($columns), '?'));
            $this->inserts[$key] = $this->staging->prepare("INSERT INTO \"{$table}\" ({$names}) VALUES ({$marks})");
        }

        $statement = $this->inserts[$key];

        foreach ($columns as $index => $column) {
            $value = $row[$column];
            $statement->bindValue($index + 1, $value, match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            });
        }

        $statement->execute();
        $this->counts[$table]++;

        if (++$this->pending >= self::BATCH) {
            $this->staging->commit();
            $this->staging->beginTransaction();
            $this->pending = 0;
        }
    }

    /**
     * Rows of the VALUES part of an INSERT: (1,'a',NULL),(2,'b\'c',3.50);
     *
     * @return iterable<list<int|string|null>>
     */
    private function rows(string $values): iterable
    {
        $pattern = "/\G\s*(?:'((?:[^'\\\\]++|\\\\.|'')*+)'|(NULL)\b|(-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?)|0x([0-9A-Fa-f]*)|(_[A-Za-z0-9]+\s*)|([(),;])|(.))/s";
        $offset = 0;
        $length = strlen($values);
        $row = null;

        while ($offset < $length && preg_match($pattern, $values, $token, PREG_UNMATCHED_AS_NULL, $offset)) {
            $offset += strlen($token[0]);

            if ($token[1] !== null) {
                $row[] = self::unescape($token[1]);
            } elseif ($token[2] !== null) {
                $row[] = null;
            } elseif ($token[3] !== null) {
                $number = $token[3];
                $row[] = preg_match('/^-?\d{1,18}$/', $number) ? (int) $number : $number;
            } elseif ($token[4] !== null) {
                $row[] = (string) hex2bin(strlen($token[4]) % 2 ? '0'.$token[4] : $token[4]);
            } elseif ($token[5] !== null) {
                // Charset introducer (_binary, _utf8mb4): the string follows.
                continue;
            } elseif ($token[6] === '(') {
                $row = [];
            } elseif ($token[6] === ')') {
                if ($row !== null) {
                    yield $row;
                }

                $row = null;
            } elseif ($token[6] === ';') {
                break;
            }
        }
    }

    private static function unescape(string $value): string
    {
        if (! str_contains($value, '\\') && ! str_contains($value, "''")) {
            return $value;
        }

        return (string) preg_replace_callback('/\\\\(.)|\'\'/s', fn (array $match) => $match[0] === "''" ? "'" : match ($match[1]) {
            '0' => "\0",
            'b' => "\x08",
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'Z' => "\x1a",
            default => $match[1],
        }, $value);
    }

    /**
     * Whether the statement ends outside a quoted string (so a ";" at the end of a line closes it).
     */
    private static function outsideString(string $sql): bool
    {
        $length = strlen($sql);
        $offset = 0;

        while (($quote = strpos($sql, "'", $offset)) !== false) {
            $offset = $quote + 1;

            while (true) {
                $next = $offset + strcspn($sql, "\\'", $offset);

                if ($next >= $length) {
                    return false;
                }

                if ($sql[$next] === '\\') {
                    $offset = $next + 2;

                    continue;
                }

                if ($next + 1 < $length && $sql[$next + 1] === "'") {
                    $offset = $next + 2;

                    continue;
                }

                $offset = $next + 1;

                break;
            }
        }

        return true;
    }

    /**
     * Lines of the backup, uncompressing .gz, .bz2 and .zip on the fly.
     *
     * @return iterable<string>
     */
    private function lines(string $path): iterable
    {
        $handle = $this->open($path);

        try {
            while (($line = fgets($handle)) !== false) {
                yield $line;
            }
        } finally {
            fclose($handle);
            $this->zip?->close();
            $this->zip = null;
        }
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        $magic = (string) file_get_contents($path, false, null, 0, 4);

        $handle = match (true) {
            str_starts_with($magic, "\x1f\x8b") => fopen('compress.zlib://'.$path, 'rb'),
            str_starts_with($magic, 'BZh') && function_exists('bzopen') => fopen('compress.bzip2://'.$path, 'rb'),
            str_starts_with($magic, 'BZh') => throw new RuntimeException(__('Backup compresso in .bz2 non supportato: rigeneralo senza compressione o in formato .gz.')),
            str_starts_with($magic, "PK\x03\x04") => $this->openZip($path),
            str_starts_with($magic, "\x28\xb5\x2f\xfd") => throw new RuntimeException(__('Backup compresso in .zst non supportato: rigeneralo senza compressione o in formato .gz.')),
            default => fopen($path, 'rb'),
        };

        if (! $handle) {
            throw new RuntimeException(__('Impossibile leggere il file di backup.'));
        }

        return $handle;
    }

    /**
     * @return resource|false
     */
    private function openZip(string $path)
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($path) !== true) {
            throw new RuntimeException(__('Archivio .zip non valido.'));
        }

        $entry = null;

        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            $name = (string) $this->zip->getNameIndex($index);

            if (preg_match('/\.sql$/i', $name)) {
                $entry = $name;

                break;
            }
        }

        if ($entry === null) {
            throw new RuntimeException(__('L\'archivio .zip non contiene un file .sql.'));
        }

        return $this->zip->getStream($entry);
    }
}
