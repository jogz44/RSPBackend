<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules_exam_applicants', function (Blueprint $table) {
            // ✅ Drop old foreign key and column
            $table->dropForeign(['schedule_id']);
            $table->dropColumn('schedule_id');

            // ✅ Add new foreign key pointing to schedules_exams
            $table->foreignId('schedules_exam_id')
                ->after('id')
                ->nullable()
                ->constrained('schedules_exams')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('schedules_exam_applicants', function (Blueprint $table) {
            // Rollback
            $table->dropForeign(['schedules_exam_id']);
            $table->dropColumn('schedules_exam_id');

            $table->foreignId('schedule_id')
                ->after('id')
                ->constrained('schedules')
                ->onDelete('cascade');
        });
    }
};
