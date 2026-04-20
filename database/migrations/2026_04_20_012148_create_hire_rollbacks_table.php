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
        Schema::create('hire_rollbacks', function (Blueprint $table) {
            $table->id();
            $table->string('controlNo')->nullable(); // controlno of applicant hired
            $table->unsignedBigInteger('submission_id')->nullable(); // applicant unique submissionId
            $table->unsignedBigInteger('job_batches_rsp_id')->nullable(); // jobpostId
            $table->string('prev_submission_status')->nullable(); // status of applicant previous
            $table->string('prev_job_post_status')->nullable(); // status of  jobpost previous
            $table->timestamp('expired_at')->nullable();// expired date
            $table->timestamp('created_at')->nullable(); // expired date
            $table->timestamp('updated_at')->nullable(); // expired date

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hire_rollbacks');
    }
};
