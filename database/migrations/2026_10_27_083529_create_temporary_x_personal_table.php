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
        Schema::create('temporary_x_personal', function (Blueprint $table) {
            $table->id();
            $table->string('ControlNo')->nullable();
            $table->string('Surname')->nullable();
            $table->string('TINNo')->nullable();
            $table->string('Address')->nullable();
            $table->string('BirthDate')->nullable();
            $table->string('Firstname')->nullable();
            $table->string('MIddlename')->nullable();
            $table->string('Sex')->nullable();
            $table->string('submission_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temporary_x_personal');
    }
};
