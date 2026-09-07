<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_runtime_profiles', function (Blueprint $table): void {
            $table->string('desired_checksum', 64)->nullable()->after('desired_revision');
            $table->string('applied_checksum', 64)->nullable()->after('applied_revision');
            $table->timestamp('legacy_fpm_migrated_at')->nullable()->after('applied_checksum');
            $table->timestamp('legacy_fpm_retired_at')->nullable()->after('legacy_fpm_migrated_at');
        });
    }

    public function down(): void
    {
        Schema::table('site_runtime_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'desired_checksum',
                'applied_checksum',
                'legacy_fpm_migrated_at',
                'legacy_fpm_retired_at',
            ]);
        });
    }
};
