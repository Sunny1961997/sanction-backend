<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoamlReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'entity_reference',
        'transaction_type',
        'comments',
        'customer_id',
        'item_type',
        'item_make',
        'description',
        'disposed_value',
        'status_comments',
        'estimated_value',
        'currency_code',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
