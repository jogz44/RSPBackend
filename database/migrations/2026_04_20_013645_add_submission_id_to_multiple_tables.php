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
        // Table 1
        Schema::table('xPersonal', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('PMID');
        });

        Schema::table('xPWD', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('Visual');
        });

        // Table 2
        Schema::table('xPersonalAddt', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('Ppurok');
        });

        // Table 3
        Schema::table('xChildren', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('PMID');
        });

        Schema::table('xExperience', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('WGov');
        });

        Schema::table('xCivilService', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('LDate');
        });

        Schema::table('xEducation', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('PMID');
        });

        Schema::table('xNGO', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('OrgPosition');
        });

        Schema::table('xTrainings', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('type');
        });

        Schema::table('xSkills', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('Skills');
        });

        Schema::table('xNonAcademic', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('NonAcademic');
        });

        Schema::table('xOrganization', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('Organization');
        });

        Schema::table('tempRegAppointmentReorg', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('vicecause');
        });

        Schema::table('posting_date', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('job_batches_rsp_id');
        });
    }

    public function down(): void
    {
        // rollback (important)
        Schema::table('xPersonal', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });

        Schema::table('xPWD', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });

        Schema::table('xPersonalAddt', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
        Schema::table('xChildren', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
        Schema::table('xExperience', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
        Schema::table('xCivilService', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
        Schema::table('xEducation', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
        Schema::table('xNGO', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
        Schema::table('xTrainings', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
        Schema::table('xSkills', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
        Schema::table('xNonAcademic', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
        Schema::table('xOrganization', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
        Schema::table('tempRegAppointmentReorg', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
        Schema::table('posting_date', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
    }
};
