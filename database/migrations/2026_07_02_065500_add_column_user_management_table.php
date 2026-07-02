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
        $table->boolean('viewAdvanceAppointmentAccess')->default(false);
         $table->boolean('modifyAdvanceAppointmentAccess')->default(false);
        $table->boolean('reportAdvanceAppointmentAccess')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_rsp_control', function (Blueprint $table) {
          $table->dropColumn(['viewAdvanceAppointmentAccess', 'modifyAdvanceAppointmentAccess','reportAdvanceAppointmentAccess']);
        });
    }
};
