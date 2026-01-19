<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyInformation extends Model
{
    protected $fillable = [
        'name',
        'email',
        'creation_date',
        'expiration_date',
        'total_screenings',
        'remaining_screenings',
        'trade_license_number',
        'reporting_entry_id',
        'dob',
        'passport_number',
        'passport_country',
        'nationality',
        'contact_type',
        'communication_type',
        'phone_number',
        'address',
        'city',
        'state',
        'country'
    ];
}
