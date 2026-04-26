<?php

use App\Models\WifiSession;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table): void {
            $table->string('release_outcome_type')->nullable()->after('release_status')->index();
            $table->timestamp('last_reconciled_at')->nullable()->after('released_at');
            $table->unsignedInteger('reconcile_attempt_count')->default(0)->after('last_reconciled_at');
            $table->string('last_reconcile_result')->nullable()->after('reconcile_attempt_count');
            $table->timestamp('release_stuck_at')->nullable()->after('last_reconcile_result');
        });

        DB::table('wifi_sessions')
            ->orderBy('id')
            ->chunkById(100, function ($sessions): void {
                foreach ($sessions as $session) {
                    $outcomeType = match ($session->release_status) {
                        WifiSession::RELEASE_STATUS_SUCCEEDED => WifiSession::RELEASE_OUTCOME_SUCCESS,
                        WifiSession::RELEASE_STATUS_UNCERTAIN => WifiSession::RELEASE_OUTCOME_UNCERTAIN_CONTROLLER_STATE,
                        WifiSession::RELEASE_STATUS_MANUAL_REQUIRED => WifiSession::RELEASE_OUTCOME_MANUAL_FOLLOWUP_REQUIRED,
                        default => null,
                    };

                    DB::table('wifi_sessions')
                        ->where('id', $session->id)
                        ->update([
                            'release_outcome_type' => $outcomeType,
                            'last_reconciled_at' => null,
                            'reconcile_attempt_count' => 0,
                            'last_reconcile_result' => null,
                            'release_stuck_at' => null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'release_outcome_type',
                'last_reconciled_at',
                'reconcile_attempt_count',
                'last_reconcile_result',
                'release_stuck_at',
            ]);
        });
    }
};
