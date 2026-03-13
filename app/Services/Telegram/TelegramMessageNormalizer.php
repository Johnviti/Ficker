<?php

namespace App\Services\Telegram;

class TelegramMessageNormalizer
{
    public function normalize(array $payload): array
    {
        $message = data_get($payload, 'message');

        if (!$message || !is_array($message)) {
            return [
                'provider' => 'telegram',
                'event_type' => 'unknown',
                'update_id' => data_get($payload, 'update_id'),
                'is_supported' => false,
            ];
        }

        $text = trim((string) data_get($message, 'text', ''));

        return [
            'provider' => 'telegram',
            'event_type' => 'message_received',
            'update_id' => data_get($payload, 'update_id'),
            'telegram_user_id' => data_get($message, 'from.id'),
            'telegram_chat_id' => data_get($message, 'chat.id'),
            'telegram_username' => data_get($message, 'from.username'),
            'text' => $text,
            'received_at' => now()->toDateTimeString(),
            'is_supported' => $text !== '',
        ];
    }
}
