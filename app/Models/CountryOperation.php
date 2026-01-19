<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CountryOperation extends Model
{
    use HasFactory;

    protected $table = 'country_operations';

    protected $fillable = [
        'customer_id',
        'country',
        'notes',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
