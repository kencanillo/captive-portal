<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controller_settings', function (Blueprint $table) {
            $table->string('hotspot_operator_username')->nullable()->after('password');
            $table->text('hotspot_operator_password')->nullable()->after('hotspot_operator_username');
        });
    }

    public function down(): void
    {
        Schema::table('controller_settings', function (Blueprint $table) {
            $table->dropColumn([
                'hotspot_operator_username',
                'hotspot_operator_password',
            ]);
        });
    }
};
