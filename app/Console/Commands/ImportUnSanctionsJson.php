<?php
// filepath: /home/sunny/Documents/sanction-api-2/app/Console/Commands/ImportUnSanctionsJson.php

namespace App\Console\Commands;

use App\Models\ScreeningSubject;
use Illuminate\Console\Command;

class ImportUnSanctionsJson extends Command
{
    protected $signature = 'sanctions:import-un
        {--file=resources/json/sanction-data/un_sanctions.json : Path to UN JSON file (relative or absolute)}
        {--debug : Print first mapped records for debugging}
        {--debug-limit=5 : How many records to print}';

    protected $description = 'Import UN sanctions JSON into screening_subjects';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->option('file'));

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $payload = json_decode(file_get_contents($path), true);

        $entries = $payload['records'] ?? null;
        if (!is_array($entries)) {
            $this->error('Invalid UN JSON. Expected: { "records": [ ... ] }.');
            return self::FAILURE;
        }

        $debug = (bool) $this->option('debug');
        $debugLimit = max(0, (int) $this->option('debug-limit'));

        $rows = [];
        $now = now();

        foreach ($entries as $i => $item) {
            if (!is_array($item) || count($item) === 0) continue;

            // Reference ID
            $sourceRecordId = $this->stringOrNull($item['reference_id'] ?? null) ?? "un:row:{$i}";

            // Name
            $name = $this->stringOrNull($item['name_full'] ?? null) ?? "UN RECORD #{$i}";

            // Name (original script)
            $nameOriginalScript = $this->stringOrNull($item['name_original_script'] ?? null);

            // Gender
            $gender = $this->stringOrNull($item['gender'] ?? null);

            // DOB
            $dob = $this->stringOrNull($item['dob'] ?? null);

            // POB
            $pob = $this->stringOrNull($item['pob'] ?? null);

            // Nationality
            $nationality = $this->stringOrNull($item['nationality'] ?? null);

            // Address
            $address = $this->stringOrNull($item['address'] ?? null);

            // Other Information
            $otherInformation = $this->stringOrNull($item['other_information'] ?? null);

            // Aliases
            $aliases = $this->stringOrNull($item['aliases_good_quality'] ?? null);


            // Raw Data
            $raw = json_encode($item, JSON_UNESCAPED_UNICODE);

            $mapped = [
                'source' => 'UN',
                'source_record_id' => $sourceRecordId,
                'source_reference' => $sourceRecordId,

                'subject_type' => (isset($item['type']) && $item['type']) ? "entity" : "person",
                'name' => $name,
                'name_original_script' => $nameOriginalScript,

                'gender' => $gender,
                'dob' => $dob,
                'pob' => $pob,

                'nationality' => $nationality,
                'address' => $address,

                'sanctions' => null, // UN data does not include sanctions in this structure
                'listed_on' => null, // No specific listed_on field in the JSON
                'remarks' => null,
                'other_information' => $otherInformation,

                'aliases' => $aliases ? json_encode($aliases, JSON_UNESCAPED_UNICODE) : null,
                'raw' => $raw,

                'record_hash' => hash('sha256', 'UN|' . $sourceRecordId),

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
            $this->warn('No UN records found to import.');
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

        $this->info('Imported/updated UN rows: ' . count($rows));
        return self::SUCCESS;
    }

    private function resolvePath(string $file): string
    {
        if (str_starts_with($file, '/')) return $file;
        return base_path($file);
    }

    private function stringOrNull($v): ?string
    {
        if (!is_string($v)) return null;
        $t = trim($v);
        return $t === '' ? null : $t;
    }
}