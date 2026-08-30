<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimization_proposals', function (Blueprint $table): void {
            // Groups are applied one at a time, so a plan needs to know which of
            // its proposals have already been written and which are still waiting.
            $table->timestamp('applied_at')->nullable()->after('accepted');
        });
    }

    public function down(): void
    {
        Schema::table('optimization_proposals', function (Blueprint $table): void {
            $table->dropColumn('applied_at');
        });
    }
};
