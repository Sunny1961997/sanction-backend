<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntityLink extends Model
{
    protected $guarded = [];

    public function sourceEntity() {
        return $this->belongsTo(SanctionEntity::class, 'source_id', 'entity_id');
    }

    public function targetEntity() {
        return $this->belongsTo(SanctionEntity::class, 'target_id', 'entity_id');
    }
}
