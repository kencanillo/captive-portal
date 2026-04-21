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
                "CREATE UNIQUE INDEX device_transfer_requests_open_request_guard_unique
                ON device_transfer_requests (active_wifi_session_id)
                WHERE active_wifi_session_id IS NOT NULL
                  AND status = 'pending_review'"
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE device_transfer_requests
                ADD COLUMN open_request_guard BIGINT
                GENERATED ALWAYS AS (
                    CASE
                        WHEN active_wifi_session_id IS NOT NULL
                            AND status = 'pending_review'
                        THEN active_wifi_session_id
                        ELSE NULL
                    END
                ) STORED"
            );

            DB::statement(
                'CREATE UNIQUE INDEX device_transfer_requests_open_request_guard_unique
                ON device_transfer_requests (open_request_guard)'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS device_transfer_requests_open_request_guard_unique');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX device_transfer_requests_open_request_guard_unique ON device_transfer_requests');
            DB::statement('ALTER TABLE device_transfer_requests DROP COLUMN open_request_guard');
        }
    }
};
