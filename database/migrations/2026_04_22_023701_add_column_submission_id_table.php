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
        Schema::table('xPersonalDiversity', function (Blueprint $table) {
            //
            $table->unsignedBigInteger('submission_id')->nullable()->after('SoloParent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('xPersonalDiversity', function (Blueprint $table) {
            $table->dropColumn('submission_id');
        });
    }
};
