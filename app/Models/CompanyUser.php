<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyUser extends Model
{
    protected $fillable = [
        'user_id',
        'company_information_id',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function companyUser()
    {
        return $this->belongsTo(User::class, 'company_user_id');
    }
    public function companyInformation()
    {
        return $this->belongsTo(CompanyInformation::class, 'company_information_id');
    }
};
