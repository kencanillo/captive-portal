<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('payment_flow')->default('qrph')->after('provider');
            $table->string('paymongo_payment_intent_id')->nullable()->index()->after('payment_flow');
            $table->string('paymongo_payment_method_id')->nullable()->index()->after('paymongo_payment_intent_id');
            $table->string('paymongo_payment_id')->nullable()->index()->after('paymongo_payment_method_id');
            $table->string('qr_reference')->nullable()->index()->after('paymongo_payment_id');
            $table->longText('qr_image_url')->nullable()->after('qr_reference');
            $table->timestamp('qr_expires_at')->nullable()->index()->after('qr_image_url');
            $table->timestamp('paid_at')->nullable()->after('qr_expires_at');
            $table->string('webhook_last_event_id')->nullable()->index()->after('paid_at');
            $table->json('webhook_last_payload')->nullable()->after('webhook_last_event_id');
            $table->timestamp('webhook_received_at')->nullable()->after('webhook_last_payload');
            $table->text('failure_reason')->nullable()->after('webhook_received_at');
            $table->decimal('amount', 10, 2)->nullable()->after('failure_reason');
            $table->string('currency', 3)->default('PHP')->after('amount');
        });

        Schema::table('wifi_sessions', function (Blueprint $table): void {
            $table->string('session_status')->default('pending_payment')->index()->after('payment_status');
            $table->text('release_failure_reason')->nullable()->after('session_status');
        });
    }

    public function down(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'session_status',
                'release_failure_reason',
            ]);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_flow',
                'paymongo_payment_intent_id',
                'paymongo_payment_method_id',
                'paymongo_payment_id',
                'qr_reference',
                'qr_image_url',
                'qr_expires_at',
                'paid_at',
                'webhook_last_event_id',
                'webhook_last_payload',
                'webhook_received_at',
                'failure_reason',
                'amount',
                'currency',
            ]);
        });
    }
};
