<?php

use App\Models\PayoutExecutionAttempt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payout_execution_attempts', 'parent_attempt_id')) {
            Schema::table('payout_execution_attempts', function (Blueprint $table): void {
                $table->foreignId('parent_attempt_id')
                    ->nullable()
                    ->after('operator_id');
            });
        }

        if (! $this->hasForeignKey('payout_execution_attempts', 'pea_parent_attempt_fk')) {
            Schema::table('payout_execution_attempts', function (Blueprint $table): void {
                $table->foreign('parent_attempt_id', 'pea_parent_attempt_fk')
                    ->references('id')
                    ->on('payout_execution_attempts')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('payout_execution_attempts', 'last_reconciled_at')) {
            Schema::table('payout_execution_attempts', function (Blueprint $table): void {
                $table->timestamp('last_reconciled_at')->nullable()->after('last_error');
            });
        }

        if (! Schema::hasColumn('payout_execution_attempts', 'stale_at')) {
            Schema::table('payout_execution_attempts', function (Blueprint $table): void {
                $table->timestamp('stale_at')->nullable()->after('last_reconciled_at');
            });
        }

        DB::table('payout_execution_attempts')
            ->where('execution_state', 'provider_acknowledged')
            ->update(['execution_state' => PayoutExecutionAttempt::STATE_DISPATCHED]);

        DB::table('payout_execution_attempts')
            ->where('execution_state', 'failed')
            ->update(['execution_state' => PayoutExecutionAttempt::STATE_TERMINAL_FAILED]);

        if (! Schema::hasTable('payout_execution_attempt_resolutions')) {
            Schema::create('payout_execution_attempt_resolutions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payout_execution_attempt_id');
                $table->foreign('payout_execution_attempt_id', 'pear_attempt_fk')
                    ->references('id')
                    ->on('payout_execution_attempts')
                    ->cascadeOnDelete();
                $table->foreignId('payout_request_id');
                $table->foreign('payout_request_id', 'pear_request_fk')
                    ->references('id')
                    ->on('payout_requests')
                    ->cascadeOnDelete();
                $table->foreignId('operator_id');
                $table->foreign('operator_id', 'pear_operator_fk')
                    ->references('id')
                    ->on('operators')
                    ->cascadeOnDelete();
                $table->string('resolution_type');
                $table->timestamp('resolved_at');
                $table->foreignId('resolved_by_user_id');
                $table->foreign('resolved_by_user_id', 'pear_resolved_by_fk')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
                $table->string('reason');
                $table->text('notes')->nullable();
                $table->string('resulting_state');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['payout_execution_attempt_id', 'resolved_at'], 'payout_exec_attempt_resolution_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_execution_attempt_resolutions');

        if (Schema::hasTable('payout_execution_attempts')) {
            if ($this->hasForeignKey('payout_execution_attempts', 'pea_parent_attempt_fk')) {
                Schema::table('payout_execution_attempts', function (Blueprint $table): void {
                    $table->dropForeign('pea_parent_attempt_fk');
                });
            }

            $columnsToDrop = array_values(array_filter([
                Schema::hasColumn('payout_execution_attempts', 'parent_attempt_id') ? 'parent_attempt_id' : null,
                Schema::hasColumn('payout_execution_attempts', 'last_reconciled_at') ? 'last_reconciled_at' : null,
                Schema::hasColumn('payout_execution_attempts', 'stale_at') ? 'stale_at' : null,
            ]));

            if ($columnsToDrop !== []) {
                Schema::table('payout_execution_attempts', function (Blueprint $table) use ($columnsToDrop): void {
                    $table->dropColumn($columnsToDrop);
                });
            }
        }
    }

    private function hasForeignKey(string $table, string $constraint): bool
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
        }

        $database = DB::getDatabaseName();

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', $database)
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->exists();
    }
};
