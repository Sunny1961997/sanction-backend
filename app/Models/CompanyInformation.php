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

    public function companyUsers()
    {
        return $this->hasMany(CompanyUser::class, 'company_information_id');
    }

    public function users()
    {
        return $this->hasManyThrough(
            User::class,
            CompanyUser::class,
            'company_information_id', // Foreign key on CompanyUser
            'id',                     // Foreign key on User
            'id',                     // Local key on CompanyInformation
            'user_id'                 // Local key on CompanyUser
        )->select('users.*'); // <-- FIX: explicitly select users.* to avoid ambiguity
    }

    // Get all screening logs for users in this company
    public function screeningLogs()
    {
        return $this->hasManyThrough(
            ScreeningLog::class,
            CompanyUser::class,
            'company_information_id', // Foreign key on CompanyUser
            'user_id',                // Foreign key on ScreeningLog
            'id',                     // Local key on CompanyInformation
            'user_id'                 // Local key on CompanyUser
        );
    }

    // Get all GOAML reports for users in this company
    public function goamlReports()
    {
        return $this->hasManyThrough(
            GoamlReport::class,
            CompanyUser::class,
            'company_information_id', // Foreign key on CompanyUser
            'user_id',                // Foreign key on GoamlReport
            'id',                     // Local key on CompanyInformation
            'user_id'                 // Local key on CompanyUser
        );
    }
}