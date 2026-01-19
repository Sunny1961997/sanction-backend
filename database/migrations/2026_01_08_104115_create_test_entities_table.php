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
        Schema::create('test_entities', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->string('entity_id')->unique(); // ID from OpenSanctions
            $table->index('entity_id');  
            $table->string('name');
            $table->index('name');  
            $table->string('schema')->index(); // Person, Company, etc.
            $table->text('aliases')->nullable(); // Comma-separated list of aliases
            $table->date('birth_date')->nullable()->index();
            $table->string('country')->nullable()->index(); // Comma-separated list of countries
            $table->text('addresses')->nullable(); // Comma-separated list of addresses
            $table->text('identifiers')->nullable(); // Comma-separated list of identifiers
            $table->text('sanctions')->nullable(); // Comma-separated list of sanctions
            $table->text('phones')->nullable(); // Comma-separated list of phone numbers
            $table->text('emails')->nullable(); // Comma-separated list of email addresses
            $table->text('programs')->nullable(); // Comma-separated list of programs
            $table->text('datasets')->nullable(); // Comma-separated list of datasets
            $table->date('first_seen')->nullable();
            $table->date('last_seen')->nullable();
            $table->enum('app_customer_type', ['individual', 'entity'])->index();
            $table->string('risk_level'); // Calculated: Critical, High, Medium
            $table->json('topics')->nullable();
            $table->string('gender')->nullable()->index();
            $table->timestamps();

            $table->index(['app_customer_type', 'birth_date']);
            $table->index(['app_customer_type', 'gender']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('test_entities');
    }
};
