<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_links', function (Blueprint $table) {
            $table->id();

            // OpenSanctions link entity id (unique)
            $table->string('link_id')->unique();

            // Keep as strings (OpenSanctions IDs), do NOT add FK constraints
            $table->string('source_id')->index();
            $table->string('target_id')->index();

            $table->string('relationship_type')->index(); // e.g. ownership, directorship
            $table->string('role')->nullable()->index();

            $table->timestamps();

            // Helpful composite index for "neighbors" queries
            $table->index(['source_id', 'relationship_type']);
            $table->index(['target_id', 'relationship_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_links');
    }
};