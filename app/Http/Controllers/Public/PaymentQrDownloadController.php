<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\PortalTokenService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class PaymentQrDownloadController extends Controller
{
    public function __invoke(string $paymentToken, PortalTokenService $portalTokenService): Response
    {
        try {
            $payment = $portalTokenService->resolvePaymentToken($paymentToken);
        } catch (InvalidArgumentException $exception) {
            abort(404);
        }

        $qrImageUrl = trim((string) $payment->qr_image_url);

        if ($qrImageUrl === '') {
            abort(404);
        }

        [$content, $contentType, $extension] = str_starts_with($qrImageUrl, 'data:')
            ? $this->decodeDataUrl($qrImageUrl)
            : $this->downloadRemoteQr($qrImageUrl, $payment->id);

        return response($content, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="brucke-qr-'.$payment->id.'.'.$extension.'"',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    private function decodeDataUrl(string $dataUrl): array
    {
        if (! preg_match('/^data:(?<mime>[-\\w.+\\/]+);base64,(?<data>.+)$/', $dataUrl, $matches)) {
            abort(422, 'QR image data is invalid.');
        }

        $content = base64_decode($matches['data'], true);

        if ($content === false) {
            abort(422, 'QR image data is invalid.');
        }

        $contentType = $matches['mime'];

        return [$content, $contentType, $this->extensionForMimeType($contentType)];
    }

    private function downloadRemoteQr(string $qrImageUrl, int $paymentId): array
    {
        try {
            $response = Http::timeout(20)
                ->accept('image/*')
                ->get($qrImageUrl)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('QR image download failed', [
                'payment_id' => $paymentId,
                'url' => $qrImageUrl,
                'error' => $exception->getMessage(),
            ]);

            abort(502, 'QR image download failed.');
        }

        $contentType = $response->header('Content-Type') ?: 'image/png';

        return [$response->body(), $contentType, $this->extensionForMimeType($contentType)];
    }

    private function extensionForMimeType(string $contentType): string
    {
        return match (strtolower(trim(explode(';', $contentType)[0]))) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => 'png',
        };
    }
}
