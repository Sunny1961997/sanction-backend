<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CorporateCustomerDetail extends Model
{
    use HasFactory;

    protected $table = 'corporate_customer_details';

    protected $fillable = [
        'customer_id',
        'company_name',
        'company_address',
        'city',
        'country_incorporated',
        'po_box',
        'customer_type',
        'office_country_code',
        'office_no',
        'mobile_country_code',
        'mobile_no',
        'email',
        'trade_license_no',
        'trade_license_issued_at',
        'trade_license_issued_by',
        'license_issue_date',
        'license_expiry_date',
        'vat_registration_no',
        'tenancy_contract_expiry_date',
        'entity_type',
        'business_activity',
        'is_entity_dealting_with_import_export',
        'has_sister_concern',
        'account_holding_bank_name',
        'product_source',
        'payment_mode',
        'delivery_channel',
        'expected_no_of_transactions',
        'expected_volume',
        'dual_use_goods',
        'kyc_documents_collected_with_form',
        'is_entity_registered_in_GOAML',
        'is_entity_having_adverse_news',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function relatedPersons()
    {
        return $this->hasMany(CorporateRelatedPerson::class, 'corporate_detail_id');
    }
}
