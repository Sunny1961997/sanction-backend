<?php
// filepath: /home/sunny/Documents/sanction-api-2/app/Console/Commands/ImportUaeIndividualsJson.php

namespace App\Console\Commands;

use App\Models\ScreeningSubject;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ImportUaeIndividualsJson extends Command
{
    protected $signature = 'sanctions:import-uae-individuals
        {--file=resources/json/sanction-data/UAE - Individuals_simple.json : Path to UAE JSON file (relative or absolute)}
        {--debug : Print first mapped records for debugging}
        {--debug-limit=5 : How many records to print}';

    protected $description = 'Import UAE individuals JSON into screening_subjects';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->option('file'));

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $payload = json_decode(file_get_contents($path), true);

        if (!is_array($payload)) {
            $this->error('Invalid UAE JSON. Expected an array of individuals.');
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

            // Name (original script)
            $nameOriginalScript = $this->stringOrNull($item['Full name (in Arabic)'] ?? null);

            // Nationality
            $nationality = $this->stringOrNull($item['Nationality'] ?? null);

            // Remarks
            $remarks = $this->stringOrNull($item['Classification'] ?? null);

            // Date of Birth
            $dob = $this->stringOrNull($item['date of birth'] ?? null);

            // Place of Birth
            $pob = $this->stringOrNull($item['place of birth'] ?? null);

            // Address
            $street = $this->stringOrNull($item['Street'] ?? null);
            $city = $this->stringOrNull($item['City'] ?? null);
            $state = $this->stringOrNull($item['State'] ?? null);
            $address = implode(', ', array_filter([$street, $city, $state]));

            // Listed On
            $listedOn = $this->parseDate($item['Release date'] ?? null);

            // Whitelisted At
            $whitelistedAt = $this->parseDate($item['End date'] ?? null);

            // Other Information
            $otherInformation = $this->stringOrNull($item['Other information'] ?? null);

            // Raw Data
            $raw = json_encode($item, JSON_UNESCAPED_UNICODE);
            $fileKey = strtolower(str_replace([' ', '.json'], ['_', ''], basename($path)));

            $mapped = [
                'source' => 'UAE',
                'source_record_id' => "uae:{$fileKey}:row:{$i}",
                'source_reference' => null,

                'subject_type' => 'individual',
                'name' => $name,
                'name_original_script' => $nameOriginalScript,

                'gender' => null,
                'dob' => $dob,
                'pob' => $pob,

                'nationality' => $nationality,
                'address' => $address,

                'sanctions' => null,
                'listed_on' => $listedOn?->toDateString(),
                'remarks' => $remarks,
                'other_information' => $otherInformation,

                'aliases' => null,
                'raw' => $raw,

                'record_hash' => hash('sha256', 'UAE|' . $name),

                'is_whitelisted' => false,
                'whitelisted_at' => $whitelistedAt,
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
            $this->warn('No UAE records found to import.');
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

        $this->info('Imported/updated UAE rows: ' . count($rows));
        return self::SUCCESS;
    }

    private function resolvePath(string $file): string
    {
        if (str_starts_with($file, '/')) return $file;
        return base_path($file);
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (!$value || strtolower($value) === 'none') return null;
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