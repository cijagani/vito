<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimization_changes', function (Blueprint $table): void {
            // Which group wrote this file. Undoing one group has to know which
            // proposals to reopen, and a path is a fragile way to guess.
            $table->string('component')->nullable()->after('target_path');
        });
    }

    public function down(): void
    {
        Schema::table('optimization_changes', function (Blueprint $table): void {
            $table->dropColumn('component');
        });
    }
};
