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
            $table->string('controller_deauthorization_status')->nullable()->after('authorization_source')->index();
            $table->unsignedInteger('controller_deauthorization_attempt_count')->default(0)->after('controller_deauthorization_status');
            $table->timestamp('controller_deauthorization_last_attempt_at')->nullable()->after('controller_deauthorization_attempt_count');
            $table->timestamp('controller_deauthorization_next_attempt_at')->nullable()->after('controller_deauthorization_last_attempt_at')->index();
            $table->text('controller_deauthorization_last_error')->nullable()->after('controller_deauthorization_next_attempt_at');
        });

        DB::table('wifi_sessions')
            ->where('session_status', 'expired')
            ->where('authorization_source', 'session_expired_local_only')
            ->update([
                'deauthorized_at' => null,
                'controller_deauthorization_status' => 'failed',
                'controller_deauthorization_attempt_count' => 1,
                'controller_deauthorization_last_attempt_at' => now(),
                'controller_deauthorization_next_attempt_at' => now(),
                'controller_deauthorization_last_error' => 'Historical expired session was marked local-only before controller deauthorization retry tracking existed.',
            ]);
    }

    public function down(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'controller_deauthorization_status',
                'controller_deauthorization_attempt_count',
                'controller_deauthorization_last_attempt_at',
                'controller_deauthorization_next_attempt_at',
                'controller_deauthorization_last_error',
            ]);
        });
    }
};
