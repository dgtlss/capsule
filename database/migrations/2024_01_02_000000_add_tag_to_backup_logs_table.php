<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->string('tag')->nullable()->after('status');
            $table->index('tag');
        });
    }

    public function down(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->dropIndex(['tag']);
            $table->dropColumn('tag');
        });
    }
};
