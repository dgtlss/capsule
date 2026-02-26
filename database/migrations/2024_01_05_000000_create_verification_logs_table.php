<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_verification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_log_id')->constrained('backup_logs')->cascadeOnDelete();
            $table->enum('status', ['passed', 'failed', 'error'])->default('error');
            $table->integer('entries_checked')->default(0);
            $table->integer('entries_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->float('duration_seconds')->default(0);
            $table->string('trigger', 20)->default('scheduled');
            $table->timestamps();

            $table->index(['backup_log_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_verification_logs');
    }
};
