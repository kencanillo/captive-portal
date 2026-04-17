<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE wifi_sessions MODIFY payment_status ENUM('pending', 'awaiting_payment', 'paid', 'expired', 'failed', 'canceled') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "UPDATE wifi_sessions SET payment_status = 'pending' WHERE payment_status IN ('awaiting_payment', 'expired', 'canceled')"
        );

        DB::statement(
            "ALTER TABLE wifi_sessions MODIFY payment_status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'pending'"
        );
    }
};
