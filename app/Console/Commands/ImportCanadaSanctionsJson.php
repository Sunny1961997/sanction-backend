<?php
// filepath: /home/sunny/Documents/sanction-api-2/app/Console/Commands/ImportCanadaSanctionsJson.php

namespace App\Console\Commands;

use App\Models\ScreeningSubject;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ImportCanadaSanctionsJson extends Command
{
    protected $signature = 'sanctions:import-canada
        {--file=resources/json/sanction-data/canada_sanction.json : Path to Canada JSON file (relative or absolute)}
        {--debug : Print first mapped records for debugging}
        {--debug-limit=5 : How many records to print}';

    protected $description = 'Import Canada sanctions JSON into screening_subjects';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->option('file'));

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $payload = json_decode(file_get_contents($path), true);
        if (!is_array($payload) || !isset($payload['record']) || !is_array($payload['record'])) {
            $this->error('Invalid Canada JSON. Expected: { "record": [ ... ] }.');
            return self::FAILURE;
        }

        $debug = (bool) $this->option('debug');
        $debugLimit = max(0, (int) $this->option('debug-limit'));

        $rows = [];
        $now = now();

        foreach ($payload['record'] as $i => $item) {
            if (!is_array($item) || count($item) === 0) continue;

            $entityOrShip = $this->stringOrNull($item['EntityOrShip'] ?? null);

            // subject_type
            $subjectType = $entityOrShip ? 'entity' : 'person';

            // name
            $given = $this->stringOrNull($item['GivenName'] ?? null);
            $last  = $this->stringOrNull($item['LastName'] ?? null);
            $name = $entityOrShip ?: trim(implode(' ', array_filter([$given, $last])));
            if ($name === '') $name = "CANADA RECORD #{$i}";

            // nationality
            $country = $this->stringOrNull($item['Country'] ?? null);

            // dob stays string (ok)
            $dob = $this->stringOrNull($item['DateOfBirthOrShipBuildDate'] ?? null);

            // aliases
            $aliases = null;
            if (array_key_exists('Aliases', $item)) {
                if (is_array($item['Aliases'])) {
                    $aliases = array_values(array_unique(array_filter(array_map(
                        fn($v) => is_string($v) ? trim($v) : '',
                        $item['Aliases']
                    ), fn($v) => $v !== '')));
                } elseif (is_string($item['Aliases']) && trim($item['Aliases']) !== '') {
                    $aliases = [trim($item['Aliases'])];
                }
                if ($aliases === []) $aliases = null;
            }

            // stable unique id per record (Schedule+Item preferred) -> NEVER NULL
            $schedule = $this->stringOrNull($item['Schedule'] ?? null);
            $itemNo   = $this->stringOrNull($item['Item'] ?? null);

            $sourceRecordId = ($schedule && $itemNo)
                ? "schedule:" . $schedule . "|item:" . $itemNo
                : $this->hashId($path, $item, $i);

            // listed_on: only store valid Y-m-d else NULL
            $listedOnRaw = $this->stringOrNull($item['DateOfListing'] ?? null);
            $listedOnDate = null;
            if ($listedOnRaw) {
                $parsed = $this->parseDate($listedOnRaw);
                $listedOnDate = $parsed?->toDateString(); // Y-m-d or null
            }

            // Source reference (<= 128, optional)
            $sourceReferenceParts = array_filter([
                $schedule ? "Schedule:{$schedule}" : null,
                $itemNo ? "Item:{$itemNo}" : null,
            ]);
            $sourceReference = $sourceReferenceParts ? implode(' ', $sourceReferenceParts) : null;
            if (is_string($sourceReference) && strlen($sourceReference) > 128) {
                $sourceReference = substr($sourceReference, 0, 128);
            }

            $mapped = [
                'source' => 'CANADA',
                'source_record_id' => $sourceRecordId,
                'source_reference' => $sourceReference,

                'subject_type' => $subjectType,
                'name' => $name,
                'name_original_script' => null,

                'gender' => null,
                'dob' => $dob,
                'pob' => null,

                'nationality' => $country,
                'address' => null,

                'sanctions' => null,
                'listed_on' => $listedOnDate,
                'remarks' => null,
                'other_information' => null,

                'aliases' => $aliases ? json_encode($aliases, JSON_UNESCAPED_UNICODE) : null,

                'is_whitelisted' => false,
                'whitelisted_at' => null,
                'whitelist_reason' => null,

                'raw' => json_encode($item, JSON_UNESCAPED_UNICODE),
                'record_hash' => hash('sha256', 'CANADA|' . $sourceRecordId),

                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Extra debug for invalid dates
            if ($debug && $listedOnRaw && $listedOnDate === null) {
                $this->warn("DEBUG: invalid DateOfListing at record #{$i}: " . json_encode($listedOnRaw));
            }

            if ($debug && count($rows) < $debugLimit) {
                $this->line("---- DEBUG input #{$i} ----");
                $this->line(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $this->line("---- DEBUG mapped #{$i} ----");
                $this->line(json_encode($mapped, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }

            $rows[] = $mapped;
        }

        if (!$rows) {
            $this->warn('No Canada records found to import.');
            return self::SUCCESS;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ScreeningSubject::query()->upsert(
                $chunk,
                ['source', 'source_record_id'],
                [
                    'source_reference',
                    'subject_type',
                    'name',
                    'name_original_script',
                    'gender',
                    'dob',
                    'pob',
                    'nationality',
                    'address',
                    'sanctions',
                    'listed_on',
                    'remarks',
                    'other_information',
                    'aliases',
                    'raw',
                    'record_hash',
                    'is_whitelisted',
                    'whitelisted_at',
                    'whitelist_reason',
                    'updated_at',
                ]
            );
        }

        $this->info('Imported/updated CANADA rows: ' . count($rows));
        return self::SUCCESS;
    }

    private function resolvePath(string $file): string
    {
        if (str_starts_with($file, '/')) return $file;
        return base_path($file);
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (!$value) return null;
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringOrNull($v): ?string
    {
        if (!is_string($v)) return null;
        $t = trim($v);
        return $t === '' ? null : $t;
    }

    private function hashId(string $file, array $row, int $i): string
    {
        return hash('sha256', $file . '|' . $i . '|' . json_encode($row));
    }
}