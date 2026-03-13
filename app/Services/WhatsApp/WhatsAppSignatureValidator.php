<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Request;

class WhatsAppSignatureValidator
{
    public function isEnabled(): bool
    {
        return (bool) config('services.whatsapp.enabled', false);
    }

    public function isVerificationTokenValid(?string $token): bool
    {
        $expectedToken = (string) config('services.whatsapp.webhook_verify_token', '');

        if ($expectedToken === '' || is_null($token)) {
            return false;
        }

        return hash_equals($expectedToken, $token);
    }

    public function isRequestSignatureValid(Request $request): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $provider = (string) config('services.whatsapp.provider', 'meta');

        if ($provider !== 'meta') {
            return false;
        }

        $secret = (string) config('services.whatsapp.webhook_secret', '');
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if ($secret === '' || $signature === '') {
            return false;
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
