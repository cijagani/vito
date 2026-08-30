<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimization_plans', function (Blueprint $table): void {
            // What the server reported for each setting after the plan was applied.
            // Stored rather than recomputed because it describes a moment: the
            // machine may have changed again since, and the question it answers is
            // whether the apply worked, not what is true now.
            $table->json('verification')->nullable()->after('ruleset_versions');
        });
    }

    public function down(): void
    {
        Schema::table('optimization_plans', function (Blueprint $table): void {
            $table->dropColumn('verification');
        });
    }
};
