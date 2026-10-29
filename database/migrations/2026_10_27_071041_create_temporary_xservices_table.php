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
        Schema::create('temporary_xservices', function (Blueprint $table) {
            $table->id();
            $table->string('ControlNo')->nullable();
            $table->string('FromDate')->nullable();
            $table->string('ToDate')->nullable();
            $table->string('DesigCode')->nullable();
            $table->string('Designation')->nullable();
            $table->string('StatCode')->nullable();
            $table->string('Status')->nullable();
            $table->string('OffCode')->nullable();
            $table->string('Office')->nullable();
            $table->string('BranCode')->nullable();
            $table->string('Branch')->nullable();
            $table->string('LVRemarks')->nullable();
            $table->string('RateDay')->nullable();
            $table->string('RateMon')->nullable();
            $table->string('RateYear')->nullable();
            $table->string('SepDate')->nullable();
            $table->string('SepCause')->nullable();
            $table->string('AppCode')->nullable();
            $table->string('DivCode')->nullable();
            $table->string('Divisions')->nullable();
            $table->string('SecCode')->nullable();
            $table->string('Sections')->nullable();
            $table->string('PlantCode')->nullable();
            $table->string('Renew')->nullable();
            $table->string('Grades')->nullable();
            $table->string('Steps')->nullable();
            $table->string('Charges')->nullable();
            $table->string('PMID')->nullable();
            $table->string('effectiveDate')->nullable();
            $table->string('submission_id')->nullable();
            $table->string('itemNo')->nullable();
            $table->string('Pages')->nullable();
            $table->string('StructureID')->nullable();
            $table->string('job_post_id')->nullable();


            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temporary_xservices');
    }
};
