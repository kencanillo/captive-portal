<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS wifi_sessions_active_client_guard_unique');
            DB::statement(
                'CREATE UNIQUE INDEX wifi_sessions_active_client_guard_unique
                ON wifi_sessions ((CASE WHEN is_active = 1 THEN client_id ELSE NULL END))'
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS wifi_sessions_active_client_guard_unique');
            DB::statement(
                'CREATE UNIQUE INDEX wifi_sessions_active_client_guard_unique
                ON wifi_sessions (client_id)
                WHERE is_active = true'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS wifi_sessions_active_client_guard_unique');
            DB::statement(
                'CREATE UNIQUE INDEX wifi_sessions_active_client_guard_unique
                ON wifi_sessions (client_id)
                WHERE is_active = 1'
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS wifi_sessions_active_client_guard_unique');
            DB::statement(
                'CREATE UNIQUE INDEX wifi_sessions_active_client_guard_unique
                ON wifi_sessions (client_id)
                WHERE is_active = 1'
            );
        }
    }
};
