<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Meilisearch\Client;

class ConfigureMeiliScreeningSubjects extends Command
{
    protected $signature = 'meili:configure-screening-subjects';
    protected $description = 'Configure Meilisearch index settings for screening_subjects';

    public function handle(): int
    {
        $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
        $index = $client->index('screening_subjects');

        $this->info('Updating searchable attributes...');
        $index->updateSearchableAttributes([
            'name',
            'aliases',
            'address', // <-- add (entity scoring / retrieval)
            'remarks',           // <-- add
            'other_information', // <-- add
        ]);

        $this->info('Updating filterable attributes...');
        $index->updateFilterableAttributes([
            'name_normalized',
            'subject_type',
            'gender',
            'nationality',
            'dob',
            'source', // <-- add (you said result based on source)
            'is_whitelisted', // optional but handy
        ]);

        $this->info('Configured Meilisearch index: screening_subjects');
        return self::SUCCESS;
    }
}