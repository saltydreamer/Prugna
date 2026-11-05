<?php

namespace App\Support\Dolibarr;

/**
 * Summary of a Dolibarr import (or simulation): what was created, updated, linked to records
 * already in Prugna or skipped, warnings with a few examples, and errors that block the import.
 */
class ImportReport
{
    private const EXAMPLES = 8;

    /** @var array<string, array{label: string, created: int, updated: int, linked: int, skipped: int}> */
    private array $sections = [];

    /** @var array<string, array{message: string, count: int, examples: list<string>}> */
    private array $warnings = [];

    /** @var array<string, array{message: string, count: int, examples: list<string>}> */
    private array $errors = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var array<string, mixed> */
    private array $source = [];

    /** @var array<string, int> */
    private array $nextNumbers = [];

    public function section(string $key, string $label): void
    {
        $this->sections[$key] ??= ['label' => $label, 'created' => 0, 'updated' => 0, 'linked' => 0, 'skipped' => 0];
    }

    /**
     * @param  'created'|'updated'|'linked'|'skipped'  $outcome
     */
    public function count(string $key, string $outcome, int $count = 1): void
    {
        $this->sections[$key][$outcome] += $count;
    }

    public function warn(string $message, ?string $example = null): void
    {
        $this->add($this->warnings, $message, $example);
    }

    public function error(string $message, ?string $example = null): void
    {
        $this->add($this->errors, $message, $example);
    }

    public function note(string $note): void
    {
        if (! in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public function source(array $source): void
    {
        $this->source = [...$this->source, ...$source];
    }

    public function nextNumber(string $series, int $next): void
    {
        $this->nextNumbers[$series] = $next;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'sections' => array_values(array_map(fn (array $section, string $key) => ['key' => $key, ...$section], $this->sections, array_keys($this->sections))),
            'errors' => array_values($this->errors),
            'warnings' => array_values($this->warnings),
            'notes' => $this->notes,
            'next_numbers' => $this->nextNumbers,
        ];
    }

    /**
     * @param  array<string, array{message: string, count: int, examples: list<string>}>  $list
     */
    private function add(array &$list, string $message, ?string $example): void
    {
        $list[$message] ??= ['message' => $message, 'count' => 0, 'examples' => []];
        $list[$message]['count']++;

        if ($example !== null && count($list[$message]['examples']) < self::EXAMPLES && ! in_array($example, $list[$message]['examples'], true)) {
            $list[$message]['examples'][] = $example;
        }
    }
}
