<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payout_execution_attempts', 'provider_state')) {
            Schema::table('payout_execution_attempts', function (Blueprint $table): void {
                $table->string('provider_state')->nullable()->after('provider_name')->index();
            });
        }

        if (! Schema::hasColumn('payout_execution_attempts', 'provider_state_source')) {
            Schema::table('payout_execution_attempts', function (Blueprint $table): void {
                $table->string('provider_state_source')->nullable()->after('provider_state');
            });
        }

        if (! Schema::hasColumn('payout_execution_attempts', 'provider_state_checked_at')) {
            Schema::table('payout_execution_attempts', function (Blueprint $table): void {
                $table->timestamp('provider_state_checked_at')->nullable()->after('provider_state_source');
            });
        }

        if (! Schema::hasColumn('payout_execution_attempts', 'last_provider_payload_hash')) {
            Schema::table('payout_execution_attempts', function (Blueprint $table): void {
                $table->string('last_provider_payload_hash', 64)->nullable()->after('provider_state_checked_at');
            });
        }
    }

    public function down(): void
    {
        $columnsToDrop = array_values(array_filter([
            Schema::hasColumn('payout_execution_attempts', 'provider_state') ? 'provider_state' : null,
            Schema::hasColumn('payout_execution_attempts', 'provider_state_source') ? 'provider_state_source' : null,
            Schema::hasColumn('payout_execution_attempts', 'provider_state_checked_at') ? 'provider_state_checked_at' : null,
            Schema::hasColumn('payout_execution_attempts', 'last_provider_payload_hash') ? 'last_provider_payload_hash' : null,
        ]));

        if ($columnsToDrop !== []) {
            Schema::table('payout_execution_attempts', function (Blueprint $table) use ($columnsToDrop): void {
                $table->dropColumn($columnsToDrop);
            });
        }
    }
};
