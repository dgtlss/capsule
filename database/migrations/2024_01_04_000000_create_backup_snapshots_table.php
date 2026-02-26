<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_log_id')->constrained('backup_logs')->cascadeOnDelete();
            $table->string('type', 20)->default('full');
            $table->unsignedBigInteger('base_snapshot_id')->nullable();
            $table->longText('file_index');
            $table->integer('total_files')->default(0);
            $table->bigInteger('total_size')->default(0);
            $table->timestamps();

            $table->index('backup_log_id');
            $table->index('type');
            $table->foreign('base_snapshot_id')->references('id')->on('backup_snapshots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_snapshots');
    }
};
