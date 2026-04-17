<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\PayMongoQrPhService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PayMongoWebhookController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, PayMongoQrPhService $payMongoQrPhService)
    {
        $payload = $request->getContent();
        $signature = $request->header('Paymongo-Signature');

        try {
            $payMongoQrPhService->handleWebhook($payload, $signature);
            return $this->success([], 'Webhook processed.');
        } catch (Throwable $e) {
            Log::error('PayMongo webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return $this->error('Webhook processing failed.', ['webhook' => [$e->getMessage()]], 400);
        }
    }
}
