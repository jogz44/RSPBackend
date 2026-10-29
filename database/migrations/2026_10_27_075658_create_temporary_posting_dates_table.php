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
        Schema::create('temporary_posting_dates', function (Blueprint $table) {
            $table->id();
            $table->string('ControlNo')->nullable();
            $table->string('post_date')->nullable();
            $table->string('end_date')->nullable();
            $table->string('job_batches_rsp_id')->nullable();
            $table->string('submission_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temporary_posting_dates');
    }
};
