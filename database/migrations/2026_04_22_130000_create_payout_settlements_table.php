<?php

use App\Models\PayoutRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payout_request_id')->constrained()->cascadeOnDelete()->unique();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->timestamp('settled_at');
            $table->foreignId('settled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('settlement_reference')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $legacySettledRequests = DB::table('payout_requests')
            ->where('status', PayoutRequest::STATUS_PAID)
            ->get();

        foreach ($legacySettledRequests as $request) {
            DB::table('payout_settlements')->insert([
                'payout_request_id' => $request->id,
                'operator_id' => $request->operator_id,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'settled_at' => $request->paid_at ?? $request->reviewed_at ?? $request->requested_at ?? now(),
                'settled_by_user_id' => $request->reviewed_by_user_id,
                'settlement_reference' => $request->provider_transfer_reference,
                'notes' => $request->review_notes ?? $request->notes,
                'metadata' => json_encode([
                    'migrated_from_legacy_status' => true,
                    'legacy_provider' => $request->provider,
                    'legacy_provider_status' => $request->provider_status,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('payout_requests')
            ->where('status', PayoutRequest::STATUS_PAID)
            ->update([
                'status' => PayoutRequest::STATUS_SETTLED,
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_SETTLED,
            ]);
    }

    public function down(): void
    {
        DB::table('payout_requests')
            ->where('status', PayoutRequest::STATUS_SETTLED)
            ->update([
                'status' => PayoutRequest::STATUS_PAID,
            ]);

        Schema::dropIfExists('payout_settlements');
    }
};
