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
            $table->string('ethnic_group')->nullable()->after('other_specify');
            $table->string('ethnic_specify')->nullable()->after('ethnic_group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nPersonalInfo', function (Blueprint $table) {
            //
            $table->dropColumn(['ethnic_group', 'ethnic_specify']);
        });
    }
};
