<?php

namespace App\Services\Telegram;

class TelegramWebhookValidator
{
    public function isEnabled(): bool
    {
        return (bool) config('services.telegram.enabled', false);
    }

    public function isSecretValid(?string $secret): bool
    {
        $expectedSecret = (string) config('services.telegram.webhook_secret', '');

        if ($expectedSecret === '' || is_null($secret)) {
            return false;
        }

        return hash_equals($expectedSecret, $secret);
    }
}
