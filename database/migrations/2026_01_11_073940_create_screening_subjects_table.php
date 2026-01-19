<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('screening_subjects', function (Blueprint $table) {
            $table->id();

            // Source info
            $table->string('source', 32)->index(); // UN, EU, OFAC, UAE, CANADA, UK, etc.
            $table->string('source_record_id', 128)->nullable()->index(); // e.g. EU logicalId, OFAC uid, UN reference_id
            $table->string('source_reference', 128)->nullable()->index(); // e.g. EU reference number, UN reference id

            // Subject classification for screening
            $table->string('subject_type', 32)->nullable()->index(); // person/entity/organization/unknown

            // Primary name for matching
            $table->string('name')->index();
            $table->string('name_original_script')->nullable();

            // Common attributes (keep as strings because formats differ)
            $table->string('gender', 16)->nullable()->index();
            $table->string('dob', 128)->nullable()->index();
            $table->string('pob', 255)->nullable()->index();

            $table->string('nationality', 255)->nullable()->index();
            $table->text('address')->nullable();

            // Sanctions/meta text
            $table->text('sanctions')->nullable(); // e.g. "Asset freeze, Travel Ban"
            $table->date('listed_on')->nullable()->index();
            $table->text('remarks')->nullable();
            $table->text('other_information')->nullable();

            // Aliases (simple storage; can be improved later with a child table)
            $table->json('aliases')->nullable();

            // Whitelist support
            $table->boolean('is_whitelisted')->default(false)->index();
            $table->timestamp('whitelisted_at')->nullable();
            $table->string('whitelist_reason', 255)->nullable();

            // Raw payload for auditing/debug
            $table->json('raw')->nullable();

            // Optional: hash to help dedupe per source record
            $table->string('record_hash', 64)->nullable()->index();

            $table->timestamps();

            // Avoid duplicates inside same source (best-effort)
            $table->unique(['source', 'source_record_id'], 'screening_subjects_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_subjects');
    }
};