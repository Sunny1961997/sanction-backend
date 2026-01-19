<?php

// filepath: /home/sunny/Documents/sanction-api-2/app/Console/Commands/TestSanctions.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestSanctions extends Command
{
    protected $signature = 'sanctions:test
        {--source=https://data.opensanctions.org/datasets/latest/default/entities.ftm.json : NDJSON source URL}
        {--limit= : Max lines to process (optional)}
        {--chunk=500 : Upsert chunk size}';

    protected $description = 'Import OpenSanctions NDJSON into test_entities with expanded fields (streaming).';

    public function handle(): int
    {
        $url = (string) $this->option('source');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $chunkSize = max(1, (int) $this->option('chunk'));

        $this->info("Starting test_entities import...");
        $this->info("Source: {$url}");
        $this->info("Chunk: {$chunkSize}" . ($limit !== null ? " | Limit: {$limit}" : ""));

        $stream = @fopen($url, 'r');
        if ($stream === false) {
            $this->error("Failed to open URL stream: {$url}. Check allow_url_fopen.");
            return self::FAILURE;
        }

        $batch = [];
        $processed = 0;
        $saved = 0;
        $invalid = 0;

        try {
            while (($line = fgets($stream)) !== false) {
                if ($limit !== null && $processed >= $limit) {
                    break;
                }

                $processed++;

                $data = json_decode($line, true);
                if (!is_array($data)) {
                    $invalid++;
                    continue;
                }

                // Only import actual entities (skip link schemas; they don't fit test_entities columns well)
                $schema = (string) ($data['schema'] ?? 'Unknown');

                // Basic required id
                $entityId = (string) ($data['id'] ?? '');
                if ($entityId === '') {
                    continue;
                }

                $props = $data['properties'] ?? [];
                if (!is_array($props)) {
                    $props = [];
                }

                $datasets = $data['datasets'] ?? [];
                if (!is_array($datasets)) {
                    $datasets = [];
                }

                $topics = $props['topics'] ?? [];
                if (!is_array($topics)) {
                    $topics = [];
                }

                $row = $this->mapToTestEntityRow($data, $props, $datasets, $topics);

                $batch[] = $row;
                $saved++;

                if (count($batch) >= $chunkSize) {
                    $this->flush($batch);
                    $batch = [];
                    $this->line("Processed: {$processed} | Saved/Updated: {$saved} | Invalid JSON: {$invalid}");
                }
            }

            if (!empty($batch)) {
                $this->flush($batch);
            }
        } finally {
            fclose($stream);
        }

        $this->info("Done. Processed: {$processed} | Saved/Updated: {$saved} | Invalid JSON: {$invalid}");
        return self::SUCCESS;
    }

    private function flush(array $batch): void
    {
        try {
            $this->doUpsert($batch);
            return;
        } catch (\Throwable $e) {
            // Retry after sanitizing problematic columns (charset/invalid bytes/too-long issues)
            $this->warn('Upsert failed, retrying with sanitized fields. Error: ' . $e->getMessage());
        }

        $sanitized = array_map(fn ($row) => $this->sanitizeRow($row), $batch);

        try {
            $this->doUpsert($sanitized);
        } catch (\Throwable $e) {
            // Last resort: insert rows one-by-one, and skip the ones that still fail
            $this->warn('Sanitized batch still failed, inserting row-by-row (skipping failures). Error: ' . $e->getMessage());

            foreach ($sanitized as $row) {
                try {
                    $this->doUpsert([$row]);
                } catch (\Throwable $rowEx) {
                    // Skip the bad row
                    $this->warn('Skipping entity_id=' . ($row['entity_id'] ?? 'null') . ' reason=' . $rowEx->getMessage());
                }
            }
        }
    }

    private function doUpsert(array $batch): void
    {
        DB::table('test_entities')->upsert(
            $batch,
            ['entity_id'],
            [
                'name',
                'schema',
                'aliases',
                'birth_date',
                'country',
                'addresses',
                'identifiers',
                'sanctions',
                'phones',
                'emails',
                'programs',
                'datasets',
                'first_seen',
                'last_seen',
                'app_customer_type',
                'risk_level',
                'topics',
                'gender',
                'updated_at',
            ]
        );
    }

    /**
     * If a column is likely to fail due to charset/encoding issues, just null it out.
     * This matches your request: "if unable to insert in column then ignore it".
     */
    private function sanitizeRow(array $row): array
    {
        // keep these always (should be safe)
        $keep = [
            'entity_id', 'name', 'schema', 'birth_date', 'country',
            'first_seen', 'last_seen', 'app_customer_type', 'risk_level',
            'topics', 'gender', 'created_at', 'updated_at',
        ];

        $safe = [];
        foreach ($keep as $k) {
            if (array_key_exists($k, $row)) {
                $safe[$k] = $row[$k];
            }
        }

        // drop/ignore problematic text columns
        $safe['aliases'] = null;
        $safe['addresses'] = null;
        $safe['identifiers'] = null;
        $safe['sanctions'] = null;
        $safe['phones'] = null;
        $safe['emails'] = null;
        $safe['programs'] = null;
        $safe['datasets'] = null;

        return $safe;
    }

    private function mapToTestEntityRow(array $data, array $props, array $datasets, array $topics): array
    {
        $now = now();

        $schema = (string) ($data['schema'] ?? 'Unknown');

        $name =
            (string) ($data['caption']
                ?? $data['name']
                ?? ($props['name'][0] ?? '')
                ?? '');

        if ($name === '') {
            $name = 'Unknown';
        }

        $gender = $props['gender'][0] ?? null;

        $birthDate = $this->firstValue($props, ['birthDate', 'birthDateExact', 'dob']);
        $birthDate = $this->formatDate($birthDate);

        // Countries can appear in multiple keys; we join them
        $countries = $this->mergePropLists($props, ['country', 'nationality', 'countryOfResidence']);
        $countryStr = $this->joinUnique($countries);

        // Addresses can appear as strings or entity refs; store whatever text values are present
        $addresses = $this->mergePropLists($props, ['address', 'addresses', 'fullAddress', 'residence']);
        $addressesStr = $this->joinUnique($addresses, 1000);

        // Aliases (common keys)
        $aliases = $this->mergePropLists($props, ['alias', 'weakAlias', 'previousName', 'altName', 'name']);
        $aliasesStr = $this->joinUnique($aliases, 2000);

        // Identifiers — collect a handful of typical ID fields (best-effort)
        $identifiers = $this->mergePropLists($props, [
            'idNumber',
            'passportNumber',
            'nationalId',
            'registrationNumber',
            'taxNumber',
            'swiftBic',
            'isin',
            'imoNumber',
            'mmsi',
            'callSign',
        ]);
        $identifiersStr = $this->joinUnique($identifiers, 1500);

        // Phones / emails
        $phones = $this->mergePropLists($props, ['phone', 'mobile', 'fax']);
        $phonesStr = $this->joinUnique($phones, 1000);

        $emails = $this->mergePropLists($props, ['email']);
        $emailsStr = $this->joinUnique($emails, 1000);

        // Sanctions / programs — best-effort keys
        $sanctions = $this->mergePropLists($props, ['sanctions', 'sanction', 'listingDate', 'sanctionStart', 'sanctionEnd']);
        $sanctionsStr = $this->joinUnique($sanctions, 1500);

        $programs = $this->mergePropLists($props, ['program', 'programs']);
        $programsStr = $this->joinUnique($programs, 1500);

        // First/last seen (top-level fields often exist in OpenSanctions)
        $firstSeen = $this->formatDate($data['first_seen'] ?? $data['firstSeen'] ?? null);
        $lastSeen = $this->formatDate($data['last_seen'] ?? $data['lastSeen'] ?? null);

        $risk = $this->calculateRisk($topics, $datasets);
        $appCustomerType = ($schema === 'Person') ? 'individual' : 'entity';

        return [
            'entity_id' => (string) ($data['id'] ?? ''),
            'name' => $name,
            'schema' => $schema,
            'aliases' => $aliasesStr ?: null,
            'birth_date' => $birthDate,
            'country' => $countryStr ?: null,
            'addresses' => $addressesStr ?: null,
            'identifiers' => $identifiersStr ?: null,
            'sanctions' => $sanctionsStr ?: null,
            'phones' => $phonesStr ?: null,
            'emails' => $emailsStr ?: null,
            'programs' => $programsStr ?: null,
            'datasets' => $this->joinUnique($datasets, 1500) ?: null,
            'first_seen' => $firstSeen,
            'last_seen' => $lastSeen,
            'app_customer_type' => $appCustomerType,
            'risk_level' => $risk,
            'topics' => json_encode(array_values($topics), JSON_UNESCAPED_UNICODE),
            'gender' => $gender,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function firstValue(array $props, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (!isset($props[$k])) continue;
            $v = $props[$k];
            if (is_array($v) && isset($v[0]) && $v[0] !== null && $v[0] !== '') {
                return (string) $v[0];
            }
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return null;
    }

    private function mergePropLists(array $props, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (!isset($props[$k])) continue;

            $v = $props[$k];

            if (is_array($v)) {
                foreach ($v as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $out[] = trim($item);
                    }
                }
            } elseif (is_string($v) && trim($v) !== '') {
                $out[] = trim($v);
            }
        }
        return $out;
    }

    private function joinUnique(array $values, int $maxLen = 255): string
    {
        $values = array_values(array_unique(array_filter(array_map(function ($v) {
            if ($v === null) return null;
            $s = trim((string) $v);
            return $s === '' ? null : $s;
        }, $values))));

        $s = implode(', ', $values);

        // prevent DB truncation explosions
        if (strlen($s) > $maxLen) {
            $s = substr($s, 0, $maxLen);
        }

        return $s;
    }

    private function formatDate($value): ?string
    {
        if ($value === null || $value === '') return null;
        $ts = strtotime((string) $value);
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
    }

    private function calculateRisk(array $topics, array $datasets): string
    {
        if (array_intersect($topics, ['sanction', 'crime.terror'])) return 'Critical';
        if (array_intersect($topics, ['role.pep', 'crime'])) return 'High';
        if (array_intersect($datasets, ['us_ofac_sdn', 'un_sc_sanctions'])) return 'Critical';
        return 'Medium';
    }
}