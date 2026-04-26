<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_point_id')->constrained()->cascadeOnDelete();
            $table->string('entry_type')->index();
            $table->string('direction', 16)->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 8)->default('PHP');
            $table->string('state')->default('posted')->index();
            $table->string('billable_key')->unique();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamp('posted_at')->nullable()->index();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('billing_ledger_entries')->nullOnDelete();
            $table->string('source')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['access_point_id', 'entry_type', 'direction'], 'billing_ledger_entries_ap_type_direction_index');
        });

        Schema::table('access_points', function (Blueprint $table): void {
            $table->string('billing_state')->default('unbilled')->after('latest_correction_reason')->index();
            $table->timestamp('billing_posted_at')->nullable()->after('billing_state');
            $table->string('billing_block_reason')->nullable()->after('billing_posted_at');
            $table->foreignId('latest_billing_entry_id')->nullable()->after('billing_block_reason')->constrained('billing_ledger_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('access_points', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('latest_billing_entry_id');
            $table->dropColumn([
                'billing_state',
                'billing_posted_at',
                'billing_block_reason',
            ]);
        });

        Schema::dropIfExists('billing_ledger_entries');
    }
};
