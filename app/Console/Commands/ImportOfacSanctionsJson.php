<?php
// filepath: /home/sunny/Documents/sanction-api-2/app/Console/Commands/ImportOfacSanctionsJson.php

namespace App\Console\Commands;

use App\Models\ScreeningSubject;
use Illuminate\Console\Command;

class ImportOfacSanctionsJson extends Command
{
    protected $signature = 'sanctions:import-ofac
        {--file=resources/json/sanction-data/consolidated_OFAC.normalized.json : Path to OFAC JSON file (relative or absolute)}
        {--debug : Print first mapped records for debugging}
        {--debug-limit=5 : How many records to print}';

    protected $description = 'Import OFAC SDN JSON into screening_subjects';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->option('file'));

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $payload = json_decode(file_get_contents($path), true);

        $entries = $payload['sdnList']['sdnEntry'] ?? null;
        if (!is_array($entries)) {
            $this->error('Invalid OFAC JSON. Expected: { "sdnList": { "sdnEntry": [ ... ] } }.');
            return self::FAILURE;
        }

        $debug = (bool) $this->option('debug');
        $debugLimit = max(0, (int) $this->option('debug-limit'));

        $rows = [];
        $now = now();

        foreach ($entries as $i => $item) {
            if (!is_array($item) || count($item) === 0) continue;

            $uid = $item['uid'] ?? null;
            $sourceRecordId = $uid !== null ? ('uid:' . (string) $uid) : $this->hashId($path, $item, $i);

            $sdnType = $this->stringOrNull($item['sdnType'] ?? null); // Individual / Entity / Vessel / etc.
            $subjectType = $this->mapSubjectType($sdnType);

            // Name:
            // - Individual: firstName + lastName (or lastName only)
            // - Entity: lastName is usually the full entity name
            $first = $this->stringOrNull($item['firstName'] ?? null);
            $last = $this->stringOrNull($item['lastName'] ?? null);
            $name = trim(implode(' ', array_filter([$first, $last])));
            if ($name === '') $name = "OFAC RECORD #{$i}";

            // Aliases: akaList.aka[*] => "firstName lastName"
            $aliases = $this->extractAliases($item);

            // Nationality: nationalityList.nationality[*].country (or .value)
            $nationality = $this->extractNationality($item);

            // DOB (string): dateOfBirthList.dateOfBirthItem[*].dateOfBirth (or .date)
            $dob = $this->extractDob($item);

            // POB (string): placeOfBirthList.placeOfBirthItem[*] join
            $pob = $this->extractPob($item);

            // Address (text): addressList.address[*] join
            $address = $this->extractAddresses($item);

            // Programs => sanctions text
            $sanctions = $this->extractPrograms($item);

            // remarks
            $remarks = $this->stringOrNull($item['remarks'] ?? null);

            // Source reference (<= 128)
            $sourceReference = $uid !== null ? ('uid:' . (string) $uid) : null;

            $mapped = [
                'source' => 'OFAC',
                'source_record_id' => $sourceRecordId,
                'source_reference' => $sourceReference,

                'subject_type' => $subjectType,
                'name' => $name,
                'name_original_script' => null,

                'gender' => null,
                'dob' => $dob,
                'pob' => $pob,

                'nationality' => $nationality,
                'address' => $address,

                'sanctions' => $sanctions,
                'listed_on' => null, // OFAC SDN List publish date is global; leaving null per record
                'remarks' => $remarks,
                'other_information' => null,

                // IMPORTANT: your DB columns are json; pass arrays, not json_encode
                'aliases' => $aliases ? json_encode($aliases, JSON_UNESCAPED_UNICODE) : null,
                'raw' => json_encode($item, JSON_UNESCAPED_UNICODE),

                'record_hash' => hash('sha256', 'OFAC|' . $sourceRecordId),

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
            $this->warn('No OFAC records found to import.');
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

        $this->info('Imported/updated OFAC rows: ' . count($rows));
        return self::SUCCESS;
    }

    private function mapSubjectType(?string $sdnType): string
    {
        $t = strtolower((string) $sdnType);
        return match (true) {
            str_contains($t, 'individual') => 'person',
            str_contains($t, 'entity') => 'entity',
            str_contains($t, 'vessel') => 'entity',
            str_contains($t, 'aircraft') => 'entity',
            default => 'unknown',
        };
    }

    private function extractPrograms(array $item): ?string
    {
        $program = $item['programList']['program'] ?? null;
        $programs = $this->asArray($program);

        $programs = array_values(array_unique(array_filter(array_map(
            fn($p) => is_string($p) ? trim($p) : null,
            $programs
        ))));

        return $programs ? implode(', ', $programs) : null;
    }

    private function extractAliases(array $item): ?array
    {
        $aka = $item['akaList']['aka'] ?? null;
        $akas = $this->asArray($aka);

        $aliases = [];
        foreach ($akas as $a) {
            if (!is_array($a)) continue;
            $first = $this->stringOrNull($a['firstName'] ?? null);
            $last  = $this->stringOrNull($a['lastName'] ?? null);

            $alias = trim(implode(' ', array_filter([$first, $last])));
            if ($alias !== '') $aliases[] = $alias;
        }

        $aliases = array_values(array_unique($aliases));
        return $aliases ?: null;
    }

    private function extractNationality(array $item): ?string
    {
        $n = $item['nationalityList']['nationality'] ?? null;
        $arr = $this->asArray($n);

        $vals = [];
        foreach ($arr as $x) {
            if (is_string($x)) {
                $vals[] = trim($x);
                continue;
            }
            if (!is_array($x)) continue;

            $v = $this->stringOrNull($x['country'] ?? null)
                ?? $this->stringOrNull($x['value'] ?? null)
                ?? $this->stringOrNull($x['name'] ?? null);

            if ($v) $vals[] = $v;
        }

        $vals = array_values(array_unique(array_filter($vals, fn($v) => $v !== '')));
        return $vals ? implode(' | ', $vals) : null;
    }

    private function extractDob(array $item): ?string
    {
        $d = $item['dateOfBirthList']['dateOfBirthItem'] ?? null;
        $arr = $this->asArray($d);

        $vals = [];
        foreach ($arr as $x) {
            if (is_string($x)) {
                $vals[] = trim($x);
                continue;
            }
            if (!is_array($x)) continue;

            $v = $this->stringOrNull($x['dateOfBirth'] ?? null)
                ?? $this->stringOrNull($x['date'] ?? null)
                ?? $this->stringOrNull($x['value'] ?? null);

            if ($v) $vals[] = $v;
        }

        $vals = array_values(array_unique($vals));
        return $vals ? implode(' | ', $vals) : null;
    }

    private function extractPob(array $item): ?string
    {
        $p = $item['placeOfBirthList']['placeOfBirthItem'] ?? null;
        $arr = $this->asArray($p);

        $vals = [];
        foreach ($arr as $x) {
            if (is_string($x)) {
                $vals[] = trim($x);
                continue;
            }
            if (!is_array($x)) continue;

            $parts = array_filter([
                $this->stringOrNull($x['city'] ?? null),
                $this->stringOrNull($x['stateOrProvince'] ?? null),
                $this->stringOrNull($x['country'] ?? null),
                $this->stringOrNull($x['place'] ?? null),
                $this->stringOrNull($x['value'] ?? null),
            ]);

            $v = trim(implode(', ', $parts));
            if ($v) $vals[] = $v;
        }

        $vals = array_values(array_unique($vals));
        return $vals ? implode(' | ', $vals) : null;
    }

    private function extractAddresses(array $item): ?string
    {
        $a = $item['addressList']['address'] ?? null;
        $arr = $this->asArray($a);

        $vals = [];
        foreach ($arr as $x) {
            if (is_string($x)) {
                $vals[] = trim($x);
                continue;
            }
            if (!is_array($x)) continue;

            $parts = array_filter([
                $this->stringOrNull($x['address1'] ?? null),
                $this->stringOrNull($x['address2'] ?? null),
                $this->stringOrNull($x['address3'] ?? null),
                $this->stringOrNull($x['city'] ?? null),
                $this->stringOrNull($x['stateOrProvince'] ?? null),
                $this->stringOrNull($x['postalCode'] ?? null),
                $this->stringOrNull($x['country'] ?? null),
            ]);

            $v = trim(implode(', ', $parts));
            if ($v) $vals[] = $v;
        }

        $vals = array_values(array_unique($vals));
        return $vals ? implode(' | ', $vals) : null;
    }

    private function asArray($value): array
    {
        if ($value === null) return [];
        return is_array($value) ? $value : [$value];
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

    private function hashId(string $file, array $row, int $i): string
    {
        return hash('sha256', $file . '|' . $i . '|' . json_encode($row));
    }
}