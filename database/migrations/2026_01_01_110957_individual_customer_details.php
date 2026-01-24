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
        Schema::create('individual_customer_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            
            // Personal & Contact Information
            $table->string('first_name');
            $table->string('last_name');
            $table->date('dob');
            $table->enum('residential_status', ['resident', 'non-resident']);
            $table->string('address');
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('nationality');
            $table->string('country_code');
            $table->string('contact_no');
            $table->string('email');

            // Additional & Similar/PEP info
            $table->string('place_of_birth')->nullable();
            $table->string('country_of_residence')->nullable();
            $table->boolean('dual_nationality')->default(false);
            $table->boolean('adverse_news')->default(false);
            $table->string('gender')->nullable();
            $table->boolean('is_pep')->default(false); // Politically Exposed Person

            // Occupation & Financial
            $table->string('occupation')->nullable();
            $table->string('source_of_income')->nullable();
            $table->string('purpose_of_onboarding')->nullable();
            $table->string('payment_mode')->nullable();

            // ID Details
            $table->string('id_type');
            $table->string('id_no');
            $table->string('issuing_authority')->nullable();
            $table->string('issuing_country')->nullable();
            $table->date('id_issue_date')->nullable();
            $table->date('id_expiry_date')->nullable();
            $table->integer('expected_no_of_transactions')->nullable();
            $table->integer('expected_volume')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('individual_customer_details');
    }
};
