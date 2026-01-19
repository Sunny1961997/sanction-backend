<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_type',
        'onboarding_type',
        'screening_fuzziness',
        'risk_level',
        'remarks',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function individualDetail()
    {
        return $this->hasOne(IndividualCustomerDetail::class);
    }

    public function corporateDetail()
    {
        return $this->hasOne(CorporateCustomerDetail::class);
    }

    public function documents()
    {
        return $this->hasMany(CustomerDocument::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'customer_product')
            ->withPivot(['quantity', 'price', 'notes'])
            ->withTimestamps();
    }

    public function countryOperations()
    {
        return $this->hasMany(CountryOperation::class);
    }
}
