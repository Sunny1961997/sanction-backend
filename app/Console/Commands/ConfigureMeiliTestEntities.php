<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Meilisearch\Client;

class ConfigureMeiliTestEntities extends Command
{
    protected $signature = 'meili:configure-test-entities';
    protected $description = 'Configure Meilisearch index settings for test_entities';

    public function handle(): int
    {
        $host = config('scout.meilisearch.host');
        $key = config('scout.meilisearch.key');

        $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
        $index = $client->index('test_entities');

        // Reset filterable attributes
        $this->info('Resetting filterable attributes...');
        $index->updateFilterableAttributes([]);

        // Verify reset
        $this->info('Current filterable attributes after reset:');
        $filterableAttributes = $index->getFilterableAttributes();
        print_r($filterableAttributes);

        // Update searchable attributes
        $this->info('Updating searchable attributes...');
        $index->updateSearchableAttributes([
            'name',
            'aliases',
        ]);

        // Update filterable attributes
        $this->info('Updating filterable attributes...');
        $index->updateFilterableAttributes([
            'birth_date',    // Matches the 'dob' column in the database
            'nationality',   // Matches the 'nationality' column in the database
            'gender',        // Matches the 'gender' column in the database
            'subject_type',  // Matches the 'subject_type' column in the database
            'name',          // Add 'name' for filtering by name
        ]);

        // Update ranking rules
        $this->info('Updating ranking rules...');
        $index->updateRankingRules([
            'words',
            'typo',
            'proximity',
            'attribute',
            'sort',
            'exactness',
        ]);

        $this->info('Configured Meilisearch index: test_entities');
        return self::SUCCESS;
    }
}