<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the existing CHECK constraint left over from the enum
    DB::statement('ALTER TABLE job_batches_user DROP CONSTRAINT CK__job_batch__statu__07B7D2E6');

        Schema::table('job_batches_user', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_batches_user', function (Blueprint $table) {
            $table->enum('status', ['pending', 'complete', 'Occupied', 'Unoccupied', 'assessed', 'not started', 'rated', 'republished'])
                ->default('pending')->change();
        });
         DB::statement("
        ALTER TABLE job_batches_user 
        ADD CONSTRAINT CK__job_batch__statu__07B7D2E6 
        CHECK (status IN ('pending','complete','Occupied','Unoccupied','assessed','not started','rated','republished'))
    ");
    }
};