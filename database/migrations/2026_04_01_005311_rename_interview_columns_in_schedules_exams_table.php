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
        Schema::table('schedules_exams', function (Blueprint $table) {
            $table->renameColumn('venue_interview', 'venue_exam'); // ✅
            $table->renameColumn('date_interview',  'date_exam');  // ✅
            $table->renameColumn('time_interview',  'time_exam');  // ✅
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules_exams', function (Blueprint $table) {
            $table->renameColumn('venue_exam', 'venue_interview'); // ↩️ rollback
            $table->renameColumn('date_exam',  'date_interview');  // ↩️ rollback
            $table->renameColumn('time_exam',  'time_interview');  // ↩️ rollback
        });
    }
};
