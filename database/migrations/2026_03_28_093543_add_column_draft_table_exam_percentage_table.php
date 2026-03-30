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
        Schema::table('draft_table', function (Blueprint $table) {
            //
            $table->decimal('exam_score')->nullable()->after('behavioral_score');
            $table->decimal('exam_percentage')->nullable()->after('exam_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('draft_table', function (Blueprint $table) {
            //
            $table->dropColumn(['exam_score', 'exam_percentage']);
        });
    }
};
