<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\PortalTokenService;
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

        if (! str_starts_with($qrImageUrl, 'data:')) {
            abort(422, 'QR image data is invalid.');
        }

        [$content, $contentType, $extension] = $this->decodeDataUrl($qrImageUrl);

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
