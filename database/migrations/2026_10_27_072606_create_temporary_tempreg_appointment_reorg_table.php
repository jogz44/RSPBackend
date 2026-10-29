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
        Schema::create('temporary_tempreg_appointment_reorg', function (Blueprint $table) {
            $table->id();
            $table->string('ControlNo')->nullable();
            $table->string('DesigCode')->nullable();
            $table->string('NewDesignation')->nullable();
            $table->string('Designation')->nullable();
            $table->string('SG')->nullable();
            $table->string('Step')->nullable();
            $table->string('Status')->nullable();


            $table->string('OffCode')->nullable();
            $table->string('NewOffice')->nullable();
            $table->string('Office')->nullable();


            $table->string('MRate')->nullable();
            $table->string('ItemNo')->nullable();
            $table->string('Pages')->nullable();

            $table->string('DivCode')->nullable();
            $table->string('SecCode')->nullable();
            $table->string('Official')->nullable();


            $table->string('Renew')->nullable();

            $table->string('StructureID')->nullable();
            $table->string('groupcode')->nullable();
            $table->string('group')->nullable();
            $table->string('unitcode')->nullable();

            $table->string('unit')->nullable();
            $table->string('sepdate')->nullable();
            $table->string('sepcause')->nullable();
            $table->string('vicename')->nullable();
            $table->string('vicecause')->nullable();

            $table->string('submission_id')->nullable();
            $table->string('deliberation_date')->nullable(); 
            $table->string('assessment_date')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temporary_tempreg_appointment_reorg');
    }
};
