<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optimization_changes', function (Blueprint $table): void {
            // The hash of what Vito wrote, as opposed to backup_hash which records
            // what was there before. Drift detection needs both: one says what to
            // restore, the other says whether anything has touched the file since.
            $table->string('applied_hash')->nullable()->after('backup_hash');
        });
    }

    public function down(): void
    {
        Schema::table('optimization_changes', function (Blueprint $table): void {
            $table->dropColumn('applied_hash');
        });
    }
};
