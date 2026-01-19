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
        Schema::create('screening_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('search_string');
            $table->string('screening_type')->default('individual'); // individual|entity|vessel
            $table->boolean('is_match')->default(false);
            $table->timestamp('screening_date');
            $table->timestamps();

            $table->index(['user_id', 'screening_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('screening_logs');
    }
};
