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
        Schema::create('employee_reassigns', function (Blueprint $table) {
            $table->id();
            $table->string('control_no')->nullable();
            $table->string('office')->nullable();
            $table->string('office2')->nullable();
            $table->string('group')->nullable();
            $table->string('division')->nullable();
            $table->string('section')->nullable();
            $table->string('unit')->nullable();
            $table->date('re_assign_date')->nullable();
            $table->boolean('active')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_reassigns');
    }
};
