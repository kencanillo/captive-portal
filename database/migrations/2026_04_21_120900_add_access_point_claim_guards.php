<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OPEN_STATUSES = "'submitted', 'pending_review', 'approved', 'adoption_pending', 'adoption_failed'";

    public function up(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX access_point_claims_open_serial_guard_unique
                ON access_point_claims (requested_serial_number_normalized)
                WHERE requested_serial_number_normalized IS NOT NULL
                  AND claim_status IN (".self::OPEN_STATUSES.')'
            );

            DB::statement(
                "CREATE UNIQUE INDEX access_point_claims_open_mac_guard_unique
                ON access_point_claims (requested_mac_address_normalized)
                WHERE requested_mac_address_normalized IS NOT NULL
                  AND claim_status IN (".self::OPEN_STATUSES.')'
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE access_point_claims
                ADD COLUMN open_serial_guard VARCHAR(191)
                GENERATED ALWAYS AS (
                    CASE
                        WHEN requested_serial_number_normalized IS NOT NULL
                            AND claim_status IN (".self::OPEN_STATUSES.")
                        THEN requested_serial_number_normalized
                        ELSE NULL
                    END
                ) STORED"
            );

            DB::statement(
                "ALTER TABLE access_point_claims
                ADD COLUMN open_mac_guard VARCHAR(191)
                GENERATED ALWAYS AS (
                    CASE
                        WHEN requested_mac_address_normalized IS NOT NULL
                            AND claim_status IN (".self::OPEN_STATUSES.")
                        THEN requested_mac_address_normalized
                        ELSE NULL
                    END
                ) STORED"
            );

            DB::statement(
                'CREATE UNIQUE INDEX access_point_claims_open_serial_guard_unique
                ON access_point_claims (open_serial_guard)'
            );

            DB::statement(
                'CREATE UNIQUE INDEX access_point_claims_open_mac_guard_unique
                ON access_point_claims (open_mac_guard)'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS access_point_claims_open_serial_guard_unique');
            DB::statement('DROP INDEX IF EXISTS access_point_claims_open_mac_guard_unique');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX access_point_claims_open_serial_guard_unique ON access_point_claims');
            DB::statement('DROP INDEX access_point_claims_open_mac_guard_unique ON access_point_claims');
            DB::statement('ALTER TABLE access_point_claims DROP COLUMN open_serial_guard');
            DB::statement('ALTER TABLE access_point_claims DROP COLUMN open_mac_guard');
        }
    }
};
