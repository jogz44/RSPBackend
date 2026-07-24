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
       Schema::table('nWorkExperience', function (Blueprint $table) {
            $table->string('attachment_path')->nullable();
        });

         Schema::table('nTrainings', function (Blueprint $table) {
            $table->string('attachment_path')->nullable();
        });

           Schema::table('nEducation', function (Blueprint $table) {
            $table->string('attachment_path')->nullable();
        });

            Schema::table('nCivilServiceEligibity', function (Blueprint $table) {
            $table->string('attachment_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nWorkExperience', function (Blueprint $table) {
            $table->dropColumn('attachment_path');
        });

        Schema::table('nTrainings', function (Blueprint $table) {
            $table->dropColumn('attachment_path');
        });

        Schema::table('nEducation', function (Blueprint $table) {
            $table->dropColumn('attachment_path');
        });

        Schema::table('nCivilServiceEligibity', function (Blueprint $table) {
            $table->dropColumn('attachment_path');
        });
    }
};
