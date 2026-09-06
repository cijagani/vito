<?php

use App\Enums\IsolatedUserManagementState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('isolated_users', function (Blueprint $table): void {
            $table->string('management_state')
                ->default(IsolatedUserManagementState::LEGACY_UNKNOWN->value)
                ->after('installed_tooling');
            $table->timestamp('managed_at')->nullable()->after('management_state');
            $table->index('management_state');
        });

        Schema::table('site_web_profiles', function (Blueprint $table): void {
            $table->string('desired_checksum', 64)->nullable()->after('desired_revision');
            $table->string('applied_checksum', 64)->nullable()->after('applied_revision');
        });

        DB::table('site_web_profiles')->update(['symlink_policy' => 'if_not_owner']);
    }

    public function down(): void
    {
        DB::table('site_web_profiles')->update(['symlink_policy' => 'legacy']);

        Schema::table('site_web_profiles', function (Blueprint $table): void {
            $table->dropColumn(['desired_checksum', 'applied_checksum']);
        });

        Schema::table('isolated_users', function (Blueprint $table): void {
            $table->dropIndex(['management_state']);
            $table->dropColumn(['management_state', 'managed_at']);
        });
    }
};
