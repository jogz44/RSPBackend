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
            // $table->string('pwd')->nullable()->after('sex');
            $table->string('gender_prefer')->nullable()->after('sex');
            $table->string('other_specify')->nullable()->after('gender_prefer');
            $table->string('Rpurok')->nullable()->after('residential_street');
            $table->string('Ppurok')->nullable()->after('permanent_street');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nPersonalInfo', function (Blueprint $table) {
            //
            $table->dropColumn([ 'gender_prefer', 'other_specify', 'Ppurok', 'Rpurok']);
        });
    }
};
