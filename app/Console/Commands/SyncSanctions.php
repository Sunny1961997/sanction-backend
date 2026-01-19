<?php

namespace App\Console\Commands;

use App\Models\SanctionEntity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSanctions extends Command
{
    protected $signature = 'sanctions:sync
        {--source=https://data.opensanctions.org/datasets/latest/default/entities.ftm.json : NDJSON source URL}
        {--limit= : Max lines to process per pass (optional)}
        {--chunk=500 : Upsert chunk size}';

    protected $description = 'Sync OpenSanctions NDJSON into sanction_entities and entity_links using streaming.';

    // Schemas that represent searchable subjects
    private array $entitySchemas = [
        'Person',
        'Company',
        'Organization',
        'LegalEntity',
        'Vessel',
        'Airplane',
    ];

    // Schemas that represent relationships
    private array $linkSchemas = [
        'Ownership',
        'Directorship',
        'Family',
        'Succession',
        'Occupancy',
        'Position',
    ];
    private array $countryMap = [];
    public function handle(): int
    {
        $source = (string) $this->option('source');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $chunk = max(1, (int) $this->option('chunk'));

        $this->countryMap = $this->loadCountryMap();

        $this->info("Source: {$source}");
        $this->info("Chunk: {$chunk}" . ($limit ? " | Limit: {$limit}" : ""));

        // PASS 1: Entities
        $this->info('Pass 1/2: entities...');
        $this->processStream($source, 'entities', $chunk, $limit);

        // PASS 2: Links
        $this->info('Pass 2/2: links...');
        $this->processStream($source, 'links', $chunk, $limit);

        $this->info('Sync Complete.');
        return self::SUCCESS;
    }

    private function processStream(string $source, string $mode, int $chunk, ?int $limit): void
    {
        $handle = @fopen($source, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open stream: {$source}");
        }

        $batch = [];
        $processed = 0;
        $saved = 0;          // entities only
        $debugPrinted = 0;   // links debug samples only

        try {
            while (($line = fgets($handle)) !== false) {
                if ($limit !== null && $processed >= $limit) {
                    break;
                }

                $processed++;

                $data = json_decode($line, true);
                if (!is_array($data)) {
                    continue;
                }

                $schema = $data['schema'] ?? null;

                if ($mode === 'entities') {
                    // IMPORTANT: this was wrong in your file (you used linkSchemas)
                    if (!in_array($schema, $this->entitySchemas, true)) {
                        continue;
                    }

                    $batch[] = $this->mapEntity($data);
                    $saved++;

                    if (count($batch) >= $chunk) {
                        $this->flushEntities($batch);
                        $batch = [];
                        $this->line("[{$mode}] processed={$processed} saved={$saved}");
                    }
                } else {
                    // links
                    if (!in_array($schema, $this->linkSchemas, true)) {
                        continue;
                    }

                    // Print only 5 samples total
                    if ($debugPrinted < 5) {
                        $this->line('[link sample] schema=' . ($schema ?? 'null') . ' keys=' . implode(',', array_keys($data['properties'] ?? [])));
                        $debugPrinted++;
                    }

                    $this->mapLink($data);
                }
            }

            // flush remaining entities
            if ($mode === 'entities' && !empty($batch)) {
                $this->flushEntities($batch);
            }
        } finally {
            fclose($handle);
        }

        $this->line("[{$mode}] done processed={$processed} saved={$saved}");
    }

    private function flushEntities(array $batch): void
    {
        $now = now();

        foreach ($batch as &$row) {
            $row['created_at'] = $row['created_at'] ?? $now;
            $row['updated_at'] = $row['updated_at'] ?? $now;
        }

        SanctionEntity::upsert(
            $batch,
            ['entity_id'],
            [
                'name',
                'app_customer_type',
                'gender',
                'birth_date',
                'country',
                'risk_level',
                'schema',
                'properties',
                'topics',
                'updated_at',
            ]
        );
    }

    private function mapEntity(array $data): array
    {
        $props = $data['properties'] ?? [];
        $topics = $props['topics'] ?? [];
        if (!is_array($topics)) {
            $topics = [];
        }

        $schema = (string) ($data['schema'] ?? 'Unknown');

        $rawCountry = $props['country'][0] ?? null;
        $country = $this->normalizeCountry($rawCountry);

        return [
            'entity_id' => (string) ($data['id'] ?? ''),
            'name' => (string) ($data['caption'] ?? ($props['name'][0] ?? '')),
            'app_customer_type' => ($schema === 'Person') ? 'individual' : 'entity',
            'gender' => $props['gender'][0] ?? null,
            'birth_date' => $this->formatDate($props['birthDate'][0] ?? null),
            'country' => $country,
            'risk_level' => $this->calculateRisk($topics, $data['datasets'] ?? []),
            'schema' => $schema,
            'properties' => json_encode($props, JSON_UNESCAPED_UNICODE),
            'topics' => json_encode(array_values($topics), JSON_UNESCAPED_UNICODE),
        ];
    }
    private function loadCountryMap(): array
    {
        $path = resource_path('json/countries.json');
        if (!is_file($path)) {
            $this->warn("countries.json not found at: {$path}");
            return [];
        }

        $json = json_decode(file_get_contents($path), true);
        $countries = $json['countries'] ?? [];

        $map = [];
        foreach ($countries as $c) {
            $iso2 = strtoupper((string) ($c['sortname'] ?? ''));
            $name = (string) ($c['name'] ?? '');
            if ($iso2 !== '' && $name !== '') {
                $map[$iso2] = $name;
            }
        }

        return $map;
    }
    private function normalizeCountry(?string $raw): ?string
    {
        if ($raw === null) return null;

        $raw = trim($raw);
        if ($raw === '') return null;

        $upper = strtoupper($raw);

        // ISO2 -> full name
        if (strlen($upper) === 2 && isset($this->countryMap[$upper])) {
            return $this->countryMap[$upper];
        }

        // already full name or unknown format
        return $raw;
    }

    private function formatDate($date): ?string
    {
        if (!$date) return null;
        $ts = strtotime((string) $date);
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
    }

    private function mapLink(array $data): void
    {
        $schema = (string) ($data['schema'] ?? '');
        $props = $data['properties'] ?? [];

        $first = static function ($v) {
            return (is_array($v) && isset($v[0])) ? $v[0] : null;
        };

        $sourceId = null;
        $targetId = null;

        // Schema-specific endpoint mapping (based on actual keys you printed)
        if ($schema === 'Occupancy') {
            $sourceId = $first($props['holder'] ?? null);
            $targetId = $first($props['post'] ?? null);
        } elseif ($schema === 'Succession') {
            $sourceId = $first($props['predecessor'] ?? null);
            $targetId = $first($props['successor'] ?? null);
        } else {
            // Generic fallback for other link schemas
            $sourceId = $first($props['person'] ?? null)
                ?? $first($props['member'] ?? null)
                ?? $first($props['owner'] ?? null)
                ?? $first($props['holder'] ?? null)
                ?? $first($props['predecessor'] ?? null)
                ?? $first($props['subject'] ?? null);

            $targetId = $first($props['organization'] ?? null)
                ?? $first($props['entity'] ?? null)
                ?? $first($props['company'] ?? null)
                ?? $first($props['post'] ?? null)
                ?? $first($props['successor'] ?? null)
                ?? $first($props['object'] ?? null)
                ?? $first($props['asset'] ?? null);
        }

        if (!$sourceId || !$targetId) {
            return;
        }

        DB::table('entity_links')->updateOrInsert(
            ['link_id' => (string) ($data['id'] ?? '')],
            [
                'source_id' => (string) $sourceId,
                'target_id' => (string) $targetId,
                'relationship_type' => strtolower($schema),
                'role' => $first($props['role'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function calculateRisk($topics, $datasets): string
    {
        $topics = is_array($topics) ? $topics : [];
        $datasets = is_array($datasets) ? $datasets : [];

        if (array_intersect($topics, ['sanction', 'crime.terror'])) return 'Critical';
        if (array_intersect($datasets, ['us_ofac_sdn', 'un_sc_sanctions'])) return 'Critical';
        if (array_intersect($topics, ['role.pep'])) return 'High';
        return 'Medium';
    }
}