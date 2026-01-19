<?php
// filepath: /home/sunny/Documents/sanction-api-2/app/Console/Commands/ImportUkSanctionsJson.php

namespace App\Console\Commands;

use App\Models\ScreeningSubject;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportUkSanctionsJson extends Command
{
    protected $signature = 'sanctions:import-uk
        {--file=resources/json/sanction-data/FCDO_SL_Sat_Jan 10 2026 UK sanction.json : Path to UK JSON file (relative or absolute)}
        {--debug : Print first mapped records for debugging}
        {--debug-limit=5 : How many records to print}';

    protected $description = 'Import UK sanctions JSON into screening_subjects';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->option('file'));

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $payload = json_decode(file_get_contents($path), true);

        if (!is_array($payload)) {
            $this->error('Invalid UK JSON. Expected an array of sanctions.');
            return self::FAILURE;
        }

        $debug = (bool) $this->option('debug');
        $debugLimit = max(0, (int) $this->option('debug-limit'));

        $rows = [];
        $now = now();

        foreach ($payload as $i => $item) {
            if (!is_array($item) || count($item) === 0) continue;

            // Unique ID
            $sourceRecordId = $this->stringOrNull($item['OFSI Group ID'] ?? null) ?? "uk:row:{$i}";

            // Name
            $name = $this->stringOrNull($item['Name'] ?? null) ?? "UK RECORD #{$i}";

            // Nationality
            $nationality = $this->stringOrNull($item['Regime Name'] ?? null);

            // Subject Type
            $subjectType = $this->stringOrNull($item['Type'] ?? 'unknown');

            // Listed On
            $listedOn = $this->parseDate($item['Date Designated'] ?? null)?->toDateString();

            // Sanctions
            $sanctions = $this->stringOrNull($item['Sanctions Imposed'] ?? null);

            // Source Reference
            $sourceReference = $this->stringOrNull($item['OFSI Group ID'] ?? null);

            $mapped = [
                'source' => 'UK',
                'source_record_id' => $sourceRecordId,
                'source_reference' => $sourceReference,

                'subject_type' => $subjectType,
                'name' => $name,
                'name_original_script' => null,

                'gender' => null,
                'dob' => null,
                'pob' => null,

                'nationality' => $nationality,
                'address' => null,

                'sanctions' => $sanctions,
                'listed_on' => $listedOn,
                'remarks' => null,
                'other_information' => null,

                'aliases' => null,
                'raw' => json_encode($item, JSON_UNESCAPED_UNICODE),

                'record_hash' => hash('sha256', 'UK|' . $sourceRecordId),

                'is_whitelisted' => false,
                'whitelisted_at' => null,
                'whitelist_reason' => null,

                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($debug && count($rows) < $debugLimit) {
                $this->line("---- DEBUG input #{$i} ----");
                $this->line(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $this->line("---- DEBUG mapped #{$i} ----");
                $this->line(json_encode($mapped, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }

            $rows[] = $mapped;
        }

        if (!$rows) {
            $this->warn('No UK records found to import.');
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

        $this->info('Imported/updated UK rows: ' . count($rows));
        return self::SUCCESS;
    }

    private function resolvePath(string $file): string
    {
        if (str_starts_with($file, '/')) return $file;
        return base_path($file);
    }

    private function parseDate(?string $value): ?\Illuminate\Support\Carbon
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
}