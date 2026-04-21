<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX wifi_sessions_active_client_guard_unique
                ON wifi_sessions (client_id)
                WHERE is_active = 1'
            );

            DB::statement(
                "CREATE UNIQUE INDEX wifi_sessions_open_extension_guard_unique
                ON wifi_sessions (extends_session_id, client_device_id)
                WHERE extends_session_id IS NOT NULL
                  AND client_device_id IS NOT NULL
                  AND merged_into_session_id IS NULL
                  AND session_status = 'pending_payment'
                  AND payment_status IN ('pending', 'awaiting_payment')"
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE wifi_sessions
                ADD COLUMN active_client_guard BIGINT UNSIGNED
                GENERATED ALWAYS AS (CASE WHEN is_active = 1 THEN client_id ELSE NULL END) VIRTUAL"
            );

            DB::statement(
                'CREATE UNIQUE INDEX wifi_sessions_active_client_guard_unique
                ON wifi_sessions (active_client_guard)'
            );

            DB::statement(
                "ALTER TABLE wifi_sessions
                ADD COLUMN open_extension_guard VARCHAR(191)
                GENERATED ALWAYS AS (
                    CASE
                        WHEN extends_session_id IS NOT NULL
                            AND client_device_id IS NOT NULL
                            AND merged_into_session_id IS NULL
                            AND session_status = 'pending_payment'
                            AND payment_status IN ('pending', 'awaiting_payment')
                        THEN CONCAT(extends_session_id, ':', client_device_id)
                        ELSE NULL
                    END
                ) VIRTUAL"
            );

            DB::statement(
                'CREATE UNIQUE INDEX wifi_sessions_open_extension_guard_unique
                ON wifi_sessions (open_extension_guard)'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS wifi_sessions_active_client_guard_unique');
            DB::statement('DROP INDEX IF EXISTS wifi_sessions_open_extension_guard_unique');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX wifi_sessions_active_client_guard_unique ON wifi_sessions');
            DB::statement('DROP INDEX wifi_sessions_open_extension_guard_unique ON wifi_sessions');
            DB::statement('ALTER TABLE wifi_sessions DROP COLUMN active_client_guard');
            DB::statement('ALTER TABLE wifi_sessions DROP COLUMN open_extension_guard');
        }
    }
};
