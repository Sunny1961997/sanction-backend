<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IndividualCustomerDetail extends Model
{
    use HasFactory;

    protected $table = 'individual_customer_details';

    protected $fillable = [
        'customer_id',
        'first_name',
        'last_name',
        'dob',
        'residential_status',
        'address',
        'city',
        'country',
        'nationality',
        'country_code',
        'contact_no',
        'email',
        'place_of_birth',
        'country_of_residence',
        'dual_nationality',
        'adverse_news',
        'gender',
        'is_pep',
        'occupation',
        'source_of_income',
        'purpose_of_onboarding',
        'payment_mode',
        'id_type',
        'id_no',
        'issuing_authority',
        'issuing_country',
        'id_issue_date',
        'id_expiry_date',
        'expected_no_of_transactions',
        'expected_volume',
        'mode_of_approach'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
