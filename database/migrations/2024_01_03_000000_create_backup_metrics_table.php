<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_log_id')->constrained('backup_logs')->cascadeOnDelete();
            $table->bigInteger('raw_size')->default(0);
            $table->bigInteger('compressed_size')->default(0);
            $table->integer('file_count')->default(0);
            $table->integer('directory_count')->default(0);
            $table->integer('db_dump_count')->default(0);
            $table->bigInteger('db_raw_size')->default(0);
            $table->bigInteger('files_raw_size')->default(0);
            $table->float('duration_seconds')->default(0);
            $table->float('compression_ratio')->nullable();
            $table->float('throughput_bytes_per_sec')->nullable();
            $table->json('extension_breakdown')->nullable();
            $table->timestamps();

            $table->index('backup_log_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_metrics');
    }
};
