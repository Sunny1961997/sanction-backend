<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('corporate_customer_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');

            // Company Information
            $table->string('company_name');
            $table->string('company_address');
            $table->string('city')->nullable();
            $table->string('country_incorporated');
            $table->string('po_box')->nullable();
            $table->string('customer_type');
            // $table->string('license_type')->nullable();

            // Corporate Contact
            $table->string('office_country_code')->nullable();
            $table->string('office_no')->nullable();
            $table->string('mobile_country_code')->nullable();
            $table->string('mobile_no')->nullable();
            $table->string('email');

            // Identity
            $table->string('trade_license_no')->nullable();
            $table->string('trade_license_issued_at')->nullable();
            $table->string('trade_license_issued_by')->nullable();
            $table->date('license_issue_date')->nullable();
            $table->date('license_expiry_date')->nullable();
            $table->string('vat_registration_no')->nullable();
            $table->date('tenancy_contract_expiry_date')->nullable();
            
            //Operations
            $table->string('entity_type')->nullable();
            $table->string('business_activity')->nullable();
            $table->boolean('is_entity_dealting_with_import_export')->default(false);
            $table->boolean('has_sister_concern')->default(false);
            $table->string('account_holding_bank_name')->nullable();

            //products
            $table->string('product_source')->nullable();
            $table->string('payment_mode')->nullable();
            $table->string('delivery_channel')->nullable();
            $table->integer('expected_no_of_transactions')->nullable();
            $table->integer('expected_volume')->nullable();
            $table->boolean('dual_use_goods')->default(false);


            // AML Questionnaire (Based on your image sections)
            $table->boolean('kyc_documents_collected_with_form')->default(true);
            $table->boolean('is_entity_registered_in_GOAML')->default(true);
            $table->boolean('is_entity_having_adverse_news')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corporate_customer_details');
    }
};
