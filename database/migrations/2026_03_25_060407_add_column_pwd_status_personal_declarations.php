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
        Schema::table('personal_declarations', function (Blueprint $table) {
            //
            $table->string('chronic')->nullable()->after('response_40c');
            $table->string('Psychosocial')->nullable()->after('chronic');
            $table->string('Orthopedic')->nullable()->after('Psychosocial');
            $table->string('Communication')->nullable()->after('Orthopedic');
            $table->string('Learning')->nullable()->after('Communication');
            $table->string('Mental')->nullable()->after('Learning');
            $table->string('Visual')->nullable()->after('Mental');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_declarations', function (Blueprint $table) {
            //
            $table->dropColumn(['chronic', 'Psychosocial', 'Orthopedic', 'Communication', 'Learning', 'Mental', 'Visual']);
        });
    }
};
