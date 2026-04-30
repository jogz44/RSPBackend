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
         $table->boolean('requestPublication')->default(false);
        $table->boolean('reportPlantillaAccess')->default(false);
        // applicant
        $table->boolean('viewApplicantAccess')->default(false);
        $table->boolean('modifyApplicantAccess')->default(false);
        $table->boolean('reportApplicantAccess')->default(false);

        // exam score

        //   $table->boolean('viewExamScoreAccess')->default(false);
        //   $table->boolean('modifyExamScoreAccess')->default(false);


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_rsp_control', function (Blueprint $table) {
            //

            $table->dropColumn(['requestPublication',
            'reportPlantillaAccess','viewApplicantAccess',
            'modifyApplicantAccess','reportApplicantAccess',
            ]);

        });
    }
};
