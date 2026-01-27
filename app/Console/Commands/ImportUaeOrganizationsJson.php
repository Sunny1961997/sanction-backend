<?php
// filepath: /home/sunny/Documents/sanction-api-2/app/Console/Commands/ImportUaeOrganizationsJson.php

namespace App\Console\Commands;

use App\Models\ScreeningSubject;
use Illuminate\Console\Command;

class ImportUaeOrganizationsJson extends Command
{
    protected $signature = 'sanctions:import-uae-organizations
        {--file=resources/json/sanction-data/UAE - Organizations_simple.json : Path to UAE JSON file (relative or absolute)}
        {--debug : Print first mapped records for debugging}
        {--debug-limit=5 : How many records to print}';

    protected $description = 'Import UAE organizations JSON into screening_subjects';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->option('file'));

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $payload = json_decode(file_get_contents($path), true);

        if (!is_array($payload)) {
            $this->error('Invalid UAE JSON. Expected an array of organizations.');
            return self::FAILURE;
        }

        $debug = (bool) $this->option('debug');
        $debugLimit = max(0, (int) $this->option('debug-limit'));

        $rows = [];
        $now = now();

        foreach ($payload as $i => $item) {
            if (!is_array($item) || count($item) === 0) continue;

            // Name
            $name = ($item['the name'] !== "None" && $item['the name'] !== "") ? $item['the name'] : $item['Full name (in Latin letters)'];

            // Remarks
            $remarks = $this->stringOrNull($item['Classification'] ?? null);

            // Other Information
            $otherInformation = $this->stringOrNull($item['Other information'] ?? null);

            // Raw Data
            $raw = json_encode($item, JSON_UNESCAPED_UNICODE);
            $fileKey = strtolower(str_replace([' ', '.json'], ['_', ''], basename($path)));

            $mapped = [
                'source' => 'UAE',
                'source_record_id' => "uae:{$fileKey}:row:{$i}",
                'source_reference' => null,

                'subject_type' => 'organization',
                'name' => $name,
                'name_original_script' => null,

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
            $this->warn('No UAE organization records found to import.');
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

        $this->info('Imported/updated UAE organization rows: ' . count($rows));
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