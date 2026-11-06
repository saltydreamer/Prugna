<?php

namespace App\Actions\Interventions;

use App\Models\Intervention;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Splits an intervention whose description lists several dated jobs (the Dolibarr import writes
 * one line per Dolibarr line: "30/04/2026 14:30 · 4 h 30 min · Modifica database") into one
 * intervention per job, as Prugna records them. The original keeps the first job (and the on-site
 * extras and materials); the others are new interventions (INT-…) with split_from_id.
 */
class SplitIntervention
{
    /** "30/04/2026 14:30", then optionally " · 4 h 30 min" / " · 2 h" / " · 45 min", then " · text". */
    private const LINE = '/^(\d{2}\/\d{2}\/\d{4} \d{2}:\d{2})(?: · (?:(\d+) h(?: (\d+) min)?|(\d+) min)(?= · |$))?(?: · (.*))?$/u';

    public function __construct(private SaveIntervention $save) {}

    /**
     * The jobs listed in the description, in date order, and the rest of the text.
     *
     * @return array{parts: list<array{started_at: CarbonImmutable, minutes: int, description: string}>, other: string|null}
     */
    public function parse(Intervention $intervention): array
    {
        $parts = [];
        $other = [];
        $current = null;

        foreach (preg_split('/\R/u', (string) $intervention->description) as $line) {
            if (preg_match(self::LINE, trim($line), $match)) {
                $parts[] = [
                    'started_at' => CarbonImmutable::createFromFormat('d/m/Y H:i', $match[1], config('app.display_timezone')),
                    'minutes' => (int) ($match[2] ?? 0) * 60 + (int) ($match[3] ?? 0) + (int) ($match[4] ?? 0),
                    'description' => trim($match[5] ?? ''),
                ];
                $current = array_key_last($parts);
            } elseif (trim($line) === '') {
                $current = null;
            } elseif ($current !== null) {
                // A job description on several lines.
                $parts[$current]['description'] = trim($parts[$current]['description']."\n".trim($line));
            } else {
                $other[] = trim($line);
            }
        }

        usort($parts, fn (array $a, array $b) => $a['started_at'] <=> $b['started_at']);

        return ['parts' => $parts, 'other' => $other ? implode("\n", $other) : null];
    }

    /**
     * Number of interventions the split would give (0: nothing to split).
     */
    public function count(Intervention $intervention): int
    {
        $count = count($this->parse($intervention)['parts']);

        return $count >= 2 && $this->problems($intervention) === [] ? $count : 0;
    }

    /**
     * @return list<string>
     */
    public function problems(Intervention $intervention): array
    {
        return array_values(array_filter([
            $intervention->isRunning() ? __('L\'intervento è in corso.') : null,
            $intervention->isInvoiced() ? __('L\'intervento è già in fattura.') : null,
            $intervention->isSigned() ? __('L\'intervento è firmato dal cliente.') : null,
            count($this->parse($intervention)['parts']) < 2 ? __('La descrizione non elenca più lavori con data e ora.') : null,
        ]));
    }

    /**
     * @return list<Intervention> The original (first job) followed by the new interventions.
     */
    public function handle(Intervention $intervention): array
    {
        abort_if($problems = $this->problems($intervention), 409, $problems[0] ?? '');

        ['parts' => $parts, 'other' => $other] = $this->parse($intervention);
        $notes = $intervention->internal_notes;

        return DB::transaction(function () use ($intervention, $parts, $other, $notes): array {
            $shared = $intervention->only([
                'organization_id', 'location_id', 'contact_id', 'order_id', 'category_id', 'mode', 'technician_id', 'billable', 'skip_contract',
            ]);
            $result = [];

            foreach ($parts as $index => $part) {
                $piece = $index === 0 ? $intervention : new Intervention;
                $piece->split_from_id = $index === 0 ? $intervention->split_from_id : $intervention->id;

                $result[] = $this->save->handle($piece, [
                    ...$shared,
                    'started_at' => $part['started_at']->utc(),
                    'duration_minutes' => $part['minutes'],
                    'description' => $part['description'] !== '' ? $part['description'] : ($other ?? __('Intervento')),
                    'internal_notes' => $this->join($notes, $index === 0 ? null : __('Dall\'intervento :number.', ['number' => $intervention->number]), $other),
                    // On-site extras belong to the visit: they stay on the first job.
                    ...($index === 0 ? [] : ['callout' => false, 'km' => 0, 'travel_minutes' => 0]),
                ]);
            }

            return $result;
        });
    }

    private function join(?string ...$texts): ?string
    {
        $texts = array_filter($texts, fn (?string $text) => $text !== null && trim($text) !== '');

        return $texts ? implode("\n\n", $texts) : null;
    }
}
