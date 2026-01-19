<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ScreeningSubject extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'source',
        'source_record_id',
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
        'is_whitelisted',
        'whitelisted_at',
        'whitelist_reason',
        'raw',
        'record_hash',
    ];

    protected $casts = [
        'aliases' => 'array',
        'raw' => 'array',
        'listed_on' => 'date',
        'is_whitelisted' => 'boolean',
        'whitelisted_at' => 'datetime',
    ];
    public function searchableAs(): string
    {
        // Make sure Scout uses THIS index name consistently
        return 'screening_subjects';
    }
    public function toSearchableArray(): array
    {
        $name = (string) ($this->name ?? '');
        $nameNormalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));

        return [
            'id' => $this->id,

            // searchable
            'name' => $name,
            'aliases' => $this->aliases ?? [],
            'address' => (string) ($this->address ?? ''), // <-- add
            'remarks' => (string) ($this->remarks ?? ''), // <-- add
            'other_information' => (string) ($this->other_information ?? ''), // <-- add

            // exact helper
            'name_normalized' => $nameNormalized,

            // filterable
            'source' => (string) ($this->source ?? ''), // <-- add
            'subject_type' => (string) ($this->subject_type ?? ''),
            'gender' => (string) ($this->gender ?? ''),
            'nationality' => (string) ($this->nationality ?? ''),
            'dob' => (string) ($this->dob ?? ''),
            'is_whitelisted' => (bool) ($this->is_whitelisted ?? false), // optional
        ];
    }
}