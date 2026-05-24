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
        Schema::table('users', function (Blueprint $table) {
            // Rename existing column
            $table->renameColumn('name_prefix', 'prefix');

            // Add new suffix column
            $table->string('suffix')->nullable()->after('prefix');
        });
    }


    /**
     * Reverse the migrations.
     */
   public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Remove suffix
            $table->dropColumn('suffix');

            // Rename back
            $table->renameColumn('prefix', 'name_prefix');
        });
    }
};
