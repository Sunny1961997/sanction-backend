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
        Schema::create('company_informations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->date('creation_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->integer('total_screenings')->default(0);
            $table->integer('remaining_screenings')->default(0);
            $table->string('trade_license_number')->unique()->nullable();
            $table->string('reporting_entry_id')->nullable();
            $table->date('dob')->nullable();
            $table->string('passport_number')->unique()->nullable();
            $table->string('passport_country')->nullable();
            $table->string('nationality')->nullable();
            $table->string('contact_type')->nullable();
            $table->string('communication_type')->nullable();
            $table->string('phone_number')->unique()->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_information');
    }
};
