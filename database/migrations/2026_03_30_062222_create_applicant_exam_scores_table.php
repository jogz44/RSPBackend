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
        Schema::create('applicant_exam_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')
                ->nullable()
                ->constrained('submission')
                ->cascadeOnDelete();
            $table->decimal('exam_score')->nullable()->after('exam_score');
            $table->string('exam_details')->nullable()->after('exam_score');
            $table->string('exam_type')->nullable()->after('exam_details');
            $table->integer('exam_total_score')->nullable()->after('exam_type');
            $table->string('exam_date')->nullable()->after('exam_total_score');
            $table->string('exam_remarks')->nullable()->after('exam_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applicant_exam_scores');
    }
};
