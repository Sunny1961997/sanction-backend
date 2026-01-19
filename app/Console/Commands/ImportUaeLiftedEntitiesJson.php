<?php
// filepath: /home/sunny/Documents/sanction-api-2/app/Console/Commands/ImportUaeLiftedEntitiesJson.php

namespace App\Console\Commands;

use App\Models\ScreeningSubject;
use Illuminate\Console\Command;

class ImportUaeLiftedEntitiesJson extends Command
{
    protected $signature = 'sanctions:import-uae-lifted-entities
        {--file=resources/json/sanction-data/UAE - Lifting the listing - Entities_simple.json : Path to UAE JSON file (relative or absolute)}
        {--debug : Print first mapped records for debugging}
        {--debug-limit=5 : How many records to print}';

    protected $description = 'Import UAE lifted entities JSON into screening_subjects';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->option('file'));

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $payload = json_decode(file_get_contents($path), true);

        if (!is_array($payload)) {
            $this->error('Invalid UAE JSON. Expected an array of entities.');
            return self::FAILURE;
        }

        $debug = (bool) $this->option('debug');
        $debugLimit = max(0, (int) $this->option('debug-limit'));

        $rows = [];
        $now = now();

        foreach ($payload as $i => $item) {
            if (!is_array($item) || count($item) === 0) continue;

            // Name
            $name = $this->stringOrNull($item['the name'] ?? null) ?? $this->stringOrNull($item['Full name (in Latin letters)'] ?? null);

            // Name (original script)
            $nameOriginalScript = $this->stringOrNull($item['Full name (in Arabic)'] ?? null);

            // Remarks
            $remarks = $this->stringOrNull($item['Classification'] ?? null);

            // Other Information (combine "Listing decision" and "The decision to lift the listing")
            $listingDecision = $this->stringOrNull($item['Listing decision'] ?? null);
            $liftDecision = $this->stringOrNull($item['The decision to lift the listing'] ?? null);
            $otherInformation = implode(' | ', array_filter([$listingDecision, $liftDecision]));

            // Raw Data
            $raw = json_encode($item, JSON_UNESCAPED_UNICODE);

            $mapped = [
                'source' => 'UAE',
                'source_record_id' => "uae:row:{$i}",
                'source_reference' => null,

                'subject_type' => 'entity',
                'name' => $name,
                'name_original_script' => $nameOriginalScript,

                'gender' => null,
                'dob' => null,
                'pob' => null,

                'nationality' => null,
                'address' => null,

                'sanctions' => null,
                'listed_on' => null,
                'remarks' => $remarks,
                'other_information' => $otherInformation,

                'aliases' => null,
                'raw' => $raw,

                'record_hash' => hash('sha256', 'UAE|' . $name),

                'is_whitelisted' => true,
                'whitelisted_at' => $now,
                'whitelist_reason' => 'Listing lifted',

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
            $this->warn('No UAE lifted entity records found to import.');
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

        $this->info('Imported/updated UAE lifted entity rows: ' . count($rows));
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