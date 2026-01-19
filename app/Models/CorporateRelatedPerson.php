<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CorporateRelatedPerson extends Model
{
    use HasFactory;

    protected $table = 'corporate_related_persons';

    protected $fillable = [
        'corporate_detail_id',
        'type',
        'name',
        'is_pep',
        'nationality',
        'id_type',
        'id_no',
        'id_issue',
        'id_expiry',
        'dob',
        'role',
        'ownership_percentage',
    ];

    public function corporateDetail()
    {
        return $this->belongsTo(CorporateCustomerDetail::class, 'corporate_detail_id');
    }
}
