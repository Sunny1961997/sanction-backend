<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class TestEntity extends Model
{
    use Searchable;

    protected $table = 'test_entities';

    protected $fillable = [
        'entity_id',
        'name',
        'schema',
        'aliases',
        'birth_date',
        'country',
        'gender',
        'app_customer_type',
        'risk_level',
        'topics',
    ];

    public function getScoutKey(): mixed
    {
        // Use stable external id for search->db fetch
        return $this->entity_id;
    }

    public function getScoutKeyName(): string
    {
        return 'entity_id';
    }

    public function toSearchableArray(): array
    {
        return [
            'entity_id' => $this->entity_id,
            'name' => $this->name,
            'aliases' => $this->aliases,
            'birth_date' => $this->birth_date,
            'country' => $this->country,
            'gender' => $this->gender,
            'app_customer_type' => $this->app_customer_type,
        ];
    }
}