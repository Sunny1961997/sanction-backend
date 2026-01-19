<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanction_entities', function (Blueprint $table) {
            $table->id();

            $table->string('entity_id')->unique()->index();
            $table->string('name')->index();

            // Your app uses only individual/entity
            $table->enum('app_customer_type', ['individual', 'entity'])->index();

            $table->string('risk_level')->default('Medium')->index();

            // Explicit columns for searching/filtering
            $table->string('gender')->nullable()->index();
            $table->date('birth_date')->nullable()->index();
            $table->string('country')->nullable()->index();

            $table->string('schema')->index();

            // Stored as JSON strings in your command (ok); keep JSON type
            $table->json('properties')->nullable();
            $table->json('topics')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanction_entities');
    }
};