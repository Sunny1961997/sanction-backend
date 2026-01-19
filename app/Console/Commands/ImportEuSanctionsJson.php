<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ImportEuSanctionsJson extends Command
{
    protected $signature = 'sanctions:import-eu
        {--file=resources/json/sanction-data/20251219-FULL-1_1(xsd)_EU.json}
        {--truncate}';

    protected $description = 'Import EU sanctions JSON into screening_subjects';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->option('file'));

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $payload = json_decode(file_get_contents($path), true);
        if (!is_array($payload) || !isset($payload['export']) || !is_array($payload['export'])) {
            $this->error('Invalid EU JSON. Expected top-level export array.');
            return self::FAILURE;
        }

        if ($this->option('truncate')) {
            DB::table('screening_subjects')->truncate();
        }

        $rows = [];
        $now = now();

        foreach ($payload['export'] as $i => $item) {
            if (!is_array($item) || count($item) === 0) continue;

            $logicalId = $this->stringOrNull($item['sanctionEntity@logicalId'] ?? null) ?? "eu:row:{$i}";
            $euRef = $this->stringOrNull($item['sanctionEntity@euReferenceNumber'] ?? null);

            $nodes = $item['sanctionEntity'] ?? [];
            if (!is_array($nodes)) $nodes = [];

            // subject_type
            $subjectType = $this->firstNodeValue($nodes, 'subjectType@code') ?? 'unknown';

            // names + aliases
            $allNames = $this->collectAllNodeValues($nodes, 'nameAlias@wholeName');
            $name = $allNames[0] ?? ($euRef ?: $logicalId);
            $aliasesArr = $this->dedupeAliases(array_slice($allNames, 1), $name);

            // remarks
            $remarksList = $this->collectAllNodeValues($nodes, 'remark');
            $remarks = $remarksList ? implode("\n", array_values(array_unique($remarksList))) : null;

            // sanctions
            $sanctions = $this->collectRegulationsText($nodes);

            // listed_on - prefer earliest regulation publication date, fallback to designationDate
            $listedOn = $this->earliestDateFromNodes($nodes, 'regulation@publicationDate')
                ?? $this->parseDate($item['sanctionEntity@designationDate'] ?? null);

            // gender
            $gender = $this->firstNodeValue($nodes, 'nameAlias@gender');

            // dob - from birthdate@birthdate if exists, else year
            $dob = $this->firstNodeValue($nodes, 'birthdate@birthdate')
                ?? $this->firstNodeValue($nodes, 'birthdate@year');

            // pob - from birthdate@countryIso2Code (if not 00 => convert to name)
            $birthIso2 = $this->firstNodeValue($nodes, 'birthdate@countryIso2Code');
            $pob = $this->countryNameFromIso2($birthIso2);

            // nationality - from identification@countryIso2Code (first non-00)
            $nationalityIso2 = $this->firstNon00NodeValue($nodes, 'identification@countryIso2Code');
            $nationality = $this->countryNameFromIso2($nationalityIso2);

            // address - combine address fields + country (converted)
            $address = $this->collectAddresses($nodes);

            // other_information - combine identification type + number
            $otherInformation = $this->collectIdentificationsText($nodes)
                ?? $this->stringOrNull($item['sanctionEntity@designationDetails'] ?? null);

            $rows[] = [
                'source' => 'EU',
                'source_record_id' => $logicalId,
                'source_reference' => $euRef,
                'subject_type' => $subjectType,

                'name' => $name,
                'name_original_script' => null,

                'gender' => $gender,
                'dob' => $dob,
                'pob' => $pob,
                'nationality' => $nationality,
                'address' => $address,

                'sanctions' => $sanctions,
                'listed_on' => $listedOn?->toDateString(),
                'remarks' => $remarks,
                'other_information' => $otherInformation,

                'aliases' => $aliasesArr ? json_encode($aliasesArr, JSON_UNESCAPED_UNICODE) : null,
                'raw' => json_encode($item, JSON_UNESCAPED_UNICODE),

                'record_hash' => hash('sha256', 'EU|' . $logicalId),

                'is_whitelisted' => false,
                'whitelisted_at' => null,
                'whitelist_reason' => null,

                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('screening_subjects')->upsert(
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
                    'updated_at',
                ]
            );
        }

        $this->info('Imported/updated EU rows: ' . count($rows));
        return self::SUCCESS;
    }

    private function resolvePath(string $file): string
    {
        if (str_starts_with($file, '/')) return $file;
        return base_path($file);
    }

    private function parseDate($value): ?Carbon
    {
        if (!$value) return null;
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function earliestDateFromNodes(array $nodes, string $key): ?Carbon
    {
        $dates = [];
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            $v = $this->stringOrNull($n[$key] ?? null);
            if (!$v) continue;

            $d = $this->parseDate($v);
            if ($d) $dates[] = $d;
        }

        if (!$dates) return null;

        usort($dates, fn (Carbon $a, Carbon $b) => $a->timestamp <=> $b->timestamp);
        return $dates[0];
    }

    private function firstNodeValue(array $nodes, string $key): ?string
    {
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;

            if (array_key_exists($key, $n) && is_string($n[$key])) {
                $v = trim($n[$key]);
                if ($v !== '') return $v;
            }
        }
        return null;
    }

    private function firstNon00NodeValue(array $nodes, string $key): ?string
    {
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;

            if (array_key_exists($key, $n) && is_string($n[$key])) {
                $v = trim($n[$key]);
                if ($v !== '' && $v !== '00') return $v;
            }
        }
        return null;
    }

    private function collectAllNodeValues(array $nodes, string $key): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;

            if (array_key_exists($key, $n) && is_string($n[$key])) {
                $v = trim($n[$key]);
                if ($v !== '') $out[] = $v;
            }
        }
        return $out;
    }

    private function dedupeAliases(array $aliases, string $primaryName): ?array
    {
        $aliases = array_map('trim', $aliases);
        $aliases = array_filter($aliases, fn ($v) => $v !== '' && $v !== $primaryName);
        $aliases = array_values(array_unique($aliases));
        return $aliases ?: null;
    }

    private function stringOrNull($v): ?string
    {
        if (!is_string($v)) return null;
        $t = trim($v);
        return $t === '' ? null : $t;
    }

    // as you requested (minimal fixed mapping)
    private function countryNameFromIso2(?string $iso2): ?string
    {
        $iso2 = strtoupper(trim((string) $iso2));
        if ($iso2 === '' || $iso2 === '00') return null;

        $map = Cache::rememberForever('countries_iso2_to_name', function () {
            $path = base_path('resources/json/countries.json');
            if (!is_file($path)) return [];

            $data = json_decode(file_get_contents($path), true);
            $countries = $data['countries'] ?? [];
            if (!is_array($countries)) return [];

            $m = [];
            foreach ($countries as $c) {
                if (!is_array($c)) continue;

                $code = strtoupper(trim((string) ($c['sortname'] ?? '')));
                $name = trim((string) ($c['name'] ?? ''));

                if ($code !== '' && $name !== '') {
                    $m[$code] = $name;
                }
            }
            return $m;
        });

        // If not found, return ISO2 itself (so you keep some value)
        return $map[$iso2] ?? $iso2;
    }

    private function collectAddresses(array $nodes): ?string
    {
        $addresses = [];

        foreach ($nodes as $n) {
            if (!is_array($n)) continue;

            $street = $this->stringOrNull($n['address@street'] ?? null);
            $poBox = $this->stringOrNull($n['address@poBox'] ?? null);
            $zip = $this->stringOrNull($n['address@zipCode'] ?? null);
            $region = $this->stringOrNull($n['address@region'] ?? null);
            $place = $this->stringOrNull($n['address@place'] ?? null);
            $city = $this->stringOrNull($n['address@city'] ?? null);
            $countryIso2 = $this->stringOrNull($n['address@countryIso2Code'] ?? null);
            $country = $this->countryNameFromIso2($countryIso2);

            $parts = array_values(array_filter([
                $street,
                $poBox ? "PO Box {$poBox}" : null,
                $zip,
                $city,
                $region,
                $place,
                $country,
            ]));

            if ($parts) {
                $addresses[] = implode(', ', $parts);
            }
        }

        $addresses = array_values(array_unique($addresses));
        return $addresses ? implode("\n", $addresses) : null;
    }

    private function collectIdentificationsText(array $nodes): ?string
    {
        $out = [];

        foreach ($nodes as $n) {
            if (!is_array($n)) continue;

            $type = $this->stringOrNull($n['identification@identificationTypeCode'] ?? null);
            $num = $this->stringOrNull($n['identification@number'] ?? null);

            if (!$type && !$num) continue;

            if ($type && $num) $out[] = "{$type}:{$num}";
            elseif ($type) $out[] = "{$type}";
            else $out[] = "{$num}";
        }

        $out = array_values(array_unique($out));
        return $out ? implode("\n", $out) : null;
    }

    private function collectRegulationsText(array $nodes): ?string
    {
        $out = [];

        foreach ($nodes as $n) {
            if (!is_array($n)) continue;

            $regType = $this->stringOrNull($n['regulation@regulationType'] ?? null);
            $orgType = $this->stringOrNull($n['regulation@organisationType'] ?? null);
            $pubDate = $this->stringOrNull($n['regulation@publicationDate'] ?? null);
            $entry = $this->stringOrNull($n['regulation@entryIntoForceDate'] ?? null);
            $numberTitle = $this->stringOrNull($n['regulation@numberTitle'] ?? null);
            $programme = $this->stringOrNull($n['regulation@programme'] ?? null);
            $logicalId = $this->stringOrNull($n['regulation@logicalId'] ?? null);

            if (!$regType && !$orgType && !$pubDate && !$entry && !$numberTitle && !$programme && !$logicalId) {
                continue;
            }

            $parts = array_filter([
                $regType ? "Type: {$regType}" : null,
                $orgType ? "Org: {$orgType}" : null,
                $pubDate ? "Published: {$pubDate}" : null,
                $entry ? "Entry: {$entry}" : null,
                $numberTitle ? "Ref: {$numberTitle}" : null,
                $programme ? "Programme: {$programme}" : null,
                $logicalId ? "ID: {$logicalId}" : null,
            ]);

            if ($parts) $out[] = implode(' | ', $parts);
        }

        $out = array_values(array_unique($out));
        return $out ? implode("\n", $out) : null;
    }
}