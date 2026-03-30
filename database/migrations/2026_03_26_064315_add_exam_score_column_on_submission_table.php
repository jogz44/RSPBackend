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
        Schema::table('submission', function (Blueprint $table) {
            //
            // $table->integer('exam_score')->nullable()->after('eligibility_qualification');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submission', function (Blueprint $table) {
            //

            // $table->dropColumn('exam_score');
        });
    }
};
