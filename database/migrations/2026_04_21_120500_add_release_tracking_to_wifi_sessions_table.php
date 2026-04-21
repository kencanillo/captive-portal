<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table): void {
            $table->string('release_status')->nullable()->after('session_status')->index();
            $table->unsignedInteger('release_attempt_count')->default(0)->after('release_status');
            $table->timestamp('last_release_attempt_at')->nullable()->after('release_attempt_count');
            $table->text('last_release_error')->nullable()->after('last_release_attempt_at');
            $table->boolean('controller_state_uncertain')->default(false)->after('last_release_error');
            $table->timestamp('released_at')->nullable()->after('controller_state_uncertain');
            $table->string('released_by_path')->nullable()->after('released_at');
            $table->json('release_metadata')->nullable()->after('released_by_path');
        });

        DB::table('wifi_sessions')
            ->orderBy('id')
            ->chunkById(100, function ($sessions): void {
                foreach ($sessions as $session) {
                    $releaseStatus = null;
                    $lastReleaseError = null;
                    $controllerStateUncertain = false;
                    $releasedAt = null;
                    $releasedByPath = null;

                    if ($session->payment_status === 'paid') {
                        if ($session->session_status === 'active') {
                            $releaseStatus = 'succeeded';
                            $releasedAt = $session->start_time ?? $session->updated_at ?? $session->created_at;
                            $releasedByPath = 'legacy';
                        } elseif ($session->session_status === 'merged') {
                            $releaseStatus = 'succeeded';
                            $releasedAt = $session->updated_at ?? $session->created_at;
                            $releasedByPath = 'renewal_merge';
                        } elseif ($session->session_status === 'release_failed') {
                            $releaseStatus = 'failed';
                            $lastReleaseError = $session->release_failure_reason;
                        } else {
                            $releaseStatus = 'pending';
                        }
                    }

                    DB::table('wifi_sessions')
                        ->where('id', $session->id)
                        ->update([
                            'release_status' => $releaseStatus,
                            'release_attempt_count' => 0,
                            'last_release_attempt_at' => null,
                            'last_release_error' => $lastReleaseError,
                            'controller_state_uncertain' => $controllerStateUncertain,
                            'released_at' => $releasedAt,
                            'released_by_path' => $releasedByPath,
                            'release_metadata' => null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'release_status',
                'release_attempt_count',
                'last_release_attempt_at',
                'last_release_error',
                'controller_state_uncertain',
                'released_at',
                'released_by_path',
                'release_metadata',
            ]);
        });
    }
};
