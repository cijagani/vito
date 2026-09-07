<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_runtime_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('memory_current_bytes')->nullable();
            $table->unsignedBigInteger('memory_peak_bytes')->nullable();
            $table->unsignedBigInteger('cpu_usage_usec')->nullable();
            $table->unsignedInteger('tasks_current')->nullable();
            $table->unsignedInteger('oom_kill_count')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_runtime_metrics');
    }
};
