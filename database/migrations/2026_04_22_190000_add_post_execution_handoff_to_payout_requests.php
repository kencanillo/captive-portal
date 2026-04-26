<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_requests', function (Blueprint $table): void {
            $table->string('post_execution_state')->nullable()->after('settlement_block_reason');
            $table->string('post_execution_reason')->nullable()->after('post_execution_state');
            $table->timestamp('post_execution_updated_at')->nullable()->after('post_execution_reason');
            $table->timestamp('post_execution_handed_off_at')->nullable()->after('post_execution_updated_at');
            $table->foreignId('post_execution_handed_off_by_user_id')
                ->nullable()
                ->after('post_execution_handed_off_at');
            $table->foreign('post_execution_handed_off_by_user_id', 'pr_post_exec_handoff_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::create('payout_post_execution_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payout_request_id');
            $table->foreign('payout_request_id', 'ppe_request_fk')
                ->references('id')
                ->on('payout_requests')
                ->cascadeOnDelete();
            $table->foreignId('payout_execution_attempt_id')->nullable();
            $table->foreign('payout_execution_attempt_id', 'ppe_attempt_fk')
                ->references('id')
                ->on('payout_execution_attempts')
                ->nullOnDelete();
            $table->foreignId('operator_id');
            $table->foreign('operator_id', 'ppe_operator_fk')
                ->references('id')
                ->on('operators')
                ->cascadeOnDelete();
            $table->string('event_type');
            $table->timestamp('event_at');
            $table->foreignId('event_by_user_id')->nullable();
            $table->foreign('event_by_user_id', 'ppe_event_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->string('resulting_post_execution_state')->nullable();
            $table->string('resulting_settlement_state')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['payout_request_id', 'event_at'], 'payout_post_exec_event_request_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_post_execution_events');

        Schema::table('payout_requests', function (Blueprint $table): void {
            $table->dropForeign('pr_post_exec_handoff_by_fk');
            $table->dropColumn('post_execution_handed_off_by_user_id');
            $table->dropColumn([
                'post_execution_state',
                'post_execution_reason',
                'post_execution_updated_at',
                'post_execution_handed_off_at',
            ]);
        });
    }
};
