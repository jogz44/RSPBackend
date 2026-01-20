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
        Schema::table('nPersonalInfo', function (Blueprint $table) {
            //
            $table->string('philSys')->nullable(); // SSS number
            $table->string('umId')->nullable(); // SSS number

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nPersonalInfo', function (Blueprint $table) {
            $table->dropColumn(['philSys', 'umId']);
        });
    }
};
