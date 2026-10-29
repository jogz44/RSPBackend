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
        Schema::create('temporary_tempreg_appointment_reorg_ext', function (Blueprint $table) {
            $table->id(); 
            $table->string('ControlNo')->nullable();
            $table->string('PresAppro')->nullable();
            $table->string('PrevAppro')->nullable();
            $table->string('SalAuthorized')->nullable();
            $table->string('OtherComp')->nullable();
            $table->string('SupPosition')->nullable();
            $table->string('HSupPosition')->nullable();
            $table->text('Tool')->nullable();

            $table->string('Contact1')->nullable();
            $table->string('Contact2')->nullable();
            $table->string('Contact3')->nullable();
            $table->string('Contact4')->nullable();
            $table->string('Contact5')->nullable();
            $table->string('Contact6')->nullable();
            $table->text('ContactOthers')->nullable();

            $table->string('Working1')->nullable();
            $table->string('Working2')->nullable();
            $table->text('WorkingOthers')->nullable();

            $table->text('DescriptionSection')->nullable();
            $table->text('DescriptionFunction')->nullable();

            $table->text('StandardEduc')->nullable();
            $table->text('StandardExp')->nullable();
            $table->text('StandardTrain')->nullable();
            $table->text('StandardElig')->nullable();

            $table->string('Supervisor')->nullable();

            $table->string('Core1')->nullable();
            $table->string('Core2')->nullable();
            $table->string('Core3')->nullable();
            $table->string('Corelevel1')->nullable();
            $table->string('Corelevel2')->nullable();
            $table->string('Corelevel3')->nullable();
            $table->string('Corelevel4')->nullable();

            $table->string('Leader1')->nullable();
            $table->string('Leader2')->nullable();
            $table->string('Leader3')->nullable();
            $table->string('Leader4')->nullable();
            $table->string('leaderlevel1')->nullable();
            $table->string('leaderlevel2')->nullable();
            $table->string('leaderlevel3')->nullable();
            $table->string('leaderlevel4')->nullable();

            $table->string('StructureID')->nullable(); // structureid
            $table->string('submission_id')->nullable();
            $table->string('signing_date')->nullable();



            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temporary_tempreg_appointment_reorg_ext');
    }
};