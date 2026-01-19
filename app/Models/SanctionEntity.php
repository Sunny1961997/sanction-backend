<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SanctionEntity extends Model
{
    public function outgoingLinks()
    {
        return $this->hasMany(EntityLink::class, 'source_id', 'entity_id');
    }

    /**
     * Get all links where this entity is the END point.
     * Example: If this is a Company, this returns the People who own it.
     */
    public function incomingLinks()
    {
        return $this->hasMany(EntityLink::class, 'target_id', 'entity_id');
    }
}
