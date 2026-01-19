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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            // Ownership: Stores which user onboarded this customer
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Type Discriminators
            $table->enum('customer_type', ['individual', 'corporate']);
            $table->enum('onboarding_type', ['full', 'quick_single', 'quick_batch']);
            $table->string('status')->default('onboarded');
            // Shared Status Data
            $table->string('screening_fuzziness')->default('OFF'); // From "Screening Settings"
            $table->string('risk_level')->nullable(); // From "Additional Information"
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
