<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_points', function (Blueprint $table): void {
            $table->string('health_state')->nullable()->after('is_online')->index();
            $table->timestamp('health_checked_at')->nullable()->after('health_state')->index();
            $table->string('status_source')->nullable()->after('health_checked_at')->index();
            $table->timestamp('status_source_event_at')->nullable()->after('status_source')->index();
            $table->timestamp('last_connected_at')->nullable()->after('first_connected_at');
            $table->timestamp('first_confirmed_connected_at')->nullable()->after('last_connected_at');
            $table->timestamp('last_disconnected_at')->nullable()->after('first_confirmed_connected_at');
            $table->timestamp('last_health_mismatch_at')->nullable()->after('last_disconnected_at');
            $table->json('health_metadata')->nullable()->after('last_health_mismatch_at');
        });

        DB::table('access_points')
            ->orderBy('id')
            ->chunkById(100, function ($accessPoints): void {
                foreach ($accessPoints as $accessPoint) {
                    $eventAt = $accessPoint->last_seen_at
                        ?? $accessPoint->last_synced_at
                        ?? $accessPoint->updated_at;
                    $checkedAt = $accessPoint->last_synced_at
                        ?? $accessPoint->updated_at
                        ?? $eventAt;

                    if ($accessPoint->claim_status === 'pending') {
                        $healthState = 'pending';
                    } elseif ($accessPoint->is_online) {
                        $healthState = 'connected';
                    } else {
                        $healthState = 'disconnected';
                    }

                    DB::table('access_points')
                        ->where('id', $accessPoint->id)
                        ->update([
                            'health_state' => $healthState,
                            'health_checked_at' => $checkedAt,
                            'status_source' => 'sync',
                            'status_source_event_at' => $eventAt,
                            'last_connected_at' => $healthState === 'connected' ? ($checkedAt ?? Carbon::now()) : null,
                            'first_confirmed_connected_at' => $accessPoint->first_connected_at,
                            'last_disconnected_at' => $healthState === 'disconnected' ? ($checkedAt ?? Carbon::now()) : null,
                            'health_metadata' => json_encode([
                                'confidence' => $accessPoint->first_connected_at ? 'confirmed' : ($healthState === 'connected' ? 'observed' : 'confirmed'),
                                'controller_observations' => [
                                    'last_state' => $healthState,
                                    'connected_streak' => $accessPoint->first_connected_at ? 2 : ($healthState === 'connected' ? 1 : 0),
                                    'checked_at' => $checkedAt ? Carbon::parse($checkedAt)->toIso8601String() : null,
                                ],
                            ]),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('access_points', function (Blueprint $table): void {
            $table->dropIndex(['health_state']);
            $table->dropIndex(['health_checked_at']);
            $table->dropIndex(['status_source']);
            $table->dropIndex(['status_source_event_at']);
            $table->dropColumn([
                'health_state',
                'health_checked_at',
                'status_source',
                'status_source_event_at',
                'last_connected_at',
                'first_confirmed_connected_at',
                'last_disconnected_at',
                'last_health_mismatch_at',
                'health_metadata',
            ]);
        });
    }
};
