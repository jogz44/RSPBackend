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
        Schema::table('user_rsp_control', function (Blueprint $table) {
            //
            $table->boolean('viewSchedule')->default(false)->after('isJobDelete'); //
            $table->boolean('modifySchedule')->default(false)->after('viewSchedule'); //
            $table->boolean('viewExam')->default(false)->after('modifySchedule'); //
            $table->boolean('modifyExam')->default(false)->after('viewExam'); //
            // $table->boolean('isSchedule')->default(false)->after('isJobDelete'); //
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_rsp_control', function (Blueprint $table) {
            //
            $table->dropColumn(['viewSchedule', 'modifySchedule', 'viewExam', 'modifyExam']);
        });
    }
};
