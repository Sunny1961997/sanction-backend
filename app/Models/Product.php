<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'description',
        'price',
        'stock',
        'is_active',
    ];

    public function customers()
    {
        return $this->belongsToMany(Customer::class, 'customer_product')
            ->withPivot(['quantity', 'price', 'notes'])
            ->withTimestamps();
    }
}
