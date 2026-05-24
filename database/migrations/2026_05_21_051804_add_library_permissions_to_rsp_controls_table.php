<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLibraryPermissionsToRspControlsTable extends Migration
{
    public function up()
    {
        Schema::table('user_rsp_control', function (Blueprint $table) {
            $table->boolean('viewLibraryAccess')->default(false);
            $table->boolean('modifyLibraryAccess')->default(false);
        });
    }

    public function down()
    {
        Schema::table('user_rsp_control', function (Blueprint $table) {
            $table->dropColumn(['viewLibraryAccess', 'modifyLibraryAccess']);
        });
    }
}