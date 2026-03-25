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
        Schema::table('xPersonalAddt', function (Blueprint $table) {
            //
            $table->string('Rpurok')->nullable()->after('Rstreet');
            $table->string('Ppurok')->nullable()->after('Pstreet');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('xPersonalAddt', function (Blueprint $table) {
            //
            $table->dropColumn(['Ppurok', 'Rpurok']);
        });
    }
};
