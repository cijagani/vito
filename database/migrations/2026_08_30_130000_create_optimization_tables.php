<?php

use App\Enums\OptimizationPlanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optimization_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default(OptimizationPlanStatus::DRAFT->value);
            $table->string('source')->default('engine');
            $table->json('facts')->nullable();
            $table->json('budget')->nullable();
            $table->json('ruleset_versions')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
        });

        Schema::create('optimization_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('optimization_plan_id')->constrained()->cascadeOnDelete();
            $table->string('component');
            $table->string('config_key');
            $table->string('current_value')->nullable();
            $table->string('proposed_value');
            $table->string('severity');
            $table->string('apply_method');
            $table->text('rationale');
            $table->string('kb_ref')->nullable();
            $table->boolean('clamped')->default(false);
            $table->boolean('accepted')->default(true);
            $table->timestamps();

            $table->index(['optimization_plan_id', 'component']);
        });

        // The rollback manifest. Without a recorded original there is no way back
        // from a change, so a row is written here before any file is touched.
        Schema::create('optimization_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('optimization_plan_id')->constrained()->cascadeOnDelete();
            $table->string('target_path');
            $table->string('action');
            $table->longText('backup_content')->nullable();
            $table->string('backup_hash')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index('optimization_plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optimization_changes');
        Schema::dropIfExists('optimization_proposals');
        Schema::dropIfExists('optimization_plans');
    }
};
