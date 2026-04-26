<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_points', function (Blueprint $table): void {
            $table->string('billing_incident_state')->nullable()->after('billing_block_reason')->index();
            $table->timestamp('billing_incident_opened_at')->nullable()->after('billing_incident_state');
            $table->timestamp('billing_incident_resolved_at')->nullable()->after('billing_incident_opened_at');
            $table->timestamp('billing_eligibility_confirmed_at')->nullable()->after('billing_incident_resolved_at');
            $table->foreignId('billing_eligibility_confirmed_by_user_id')
                ->nullable()
                ->after('billing_eligibility_confirmed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('latest_billing_resolution_reason')->nullable()->after('billing_eligibility_confirmed_by_user_id');
            $table->json('billing_resolution_metadata')->nullable()->after('latest_billing_resolution_reason');
            $table->unsignedInteger('billing_charge_generation')->default(0)->after('billing_resolution_metadata');
        });
    }

    public function down(): void
    {
        Schema::table('access_points', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('billing_eligibility_confirmed_by_user_id');
            $table->dropColumn([
                'billing_incident_state',
                'billing_incident_opened_at',
                'billing_incident_resolved_at',
                'billing_eligibility_confirmed_at',
                'latest_billing_resolution_reason',
                'billing_resolution_metadata',
                'billing_charge_generation',
            ]);
        });
    }
};
