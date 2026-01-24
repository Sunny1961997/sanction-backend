<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGoamlReportsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('goaml_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('company_information_id')->constrained('company_information')->onDelete('cascade');
            $table->string('entity_reference')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('comments')->nullable();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('item_type')->nullable();
            $table->string('item_make')->nullable();
            $table->text('description')->nullable();
            $table->decimal('disposed_value', 15, 2)->nullable();
            $table->string('status_comments')->nullable();
            $table->decimal('estimated_value', 15, 2)->nullable();
            $table->string('currency_code')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('goaml_reports');
    }
}
