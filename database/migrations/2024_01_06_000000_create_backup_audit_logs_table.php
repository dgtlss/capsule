<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 50);
            $table->string('trigger', 30)->default('artisan');
            $table->string('actor')->nullable();
            $table->unsignedBigInteger('backup_log_id')->nullable();
            $table->string('policy')->nullable();
            $table->string('status', 20)->default('started');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index('action');
            $table->index('created_at');
            $table->index('backup_log_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_audit_logs');
    }
};
