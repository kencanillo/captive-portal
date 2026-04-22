<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\PayoutExecutionAttempt;
use App\Services\OperatorPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PayMongoPayoutExecutionCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        PayoutExecutionAttempt $payoutExecutionAttempt,
        OperatorPayoutService $payoutService,
    ): JsonResponse {
        $payload = $request->getContent();
        $signature = $request->header('Paymongo-Signature');

        try {
            $payoutService->handleExecutionProviderCallback($payoutExecutionAttempt, $payload, $signature);

            return response()->json([
                'message' => 'Payout execution callback processed.',
            ]);
        } catch (Throwable $exception) {
            Log::error('PayMongo payout execution callback processing failed.', [
                'payout_execution_attempt_id' => $payoutExecutionAttempt->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Payout execution callback rejected.',
                'error' => $exception->getMessage(),
            ], 400);
        }
    }
}
