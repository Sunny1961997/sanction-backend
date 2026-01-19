<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScreeningLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'search_string',
        'screening_type',
        'is_match',
        'screening_date',
    ];

    protected $casts = [
        'is_match' => 'boolean',
        'screening_date' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
