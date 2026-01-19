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
        Schema::create('corporate_related_persons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corporate_detail_id')->constrained('corporate_customer_details')->onDelete('cascade');
            
            $table->string('type'); // e.g., individual, entity
            $table->string('name')->nullable();
            $table->boolean('is_pep')->default(false);
            $table->string('nationality')->nullable();
            $table->string('id_type')->nullable();
            $table->string('id_no')->nullable();
            $table->date('id_issue')->nullable();
            $table->date('id_expiry')->nullable();
            $table->date('dob')->nullable();
            $table->string('role')->nullable(); // e.g., UBO, Signatory, Partner
            $table->decimal('ownership_percentage', 5, 2)->nullable(); // e.g., 25.00

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corporate_related_persons');
    }
};
