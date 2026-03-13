<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;

class TelegramSender
{
    public function sendMessage(int|string $chatId, string $text): array
    {
        $botToken = (string) config('services.telegram.bot_token', '');

        if ($botToken === '') {
            return [
                'attempted' => false,
                'success' => false,
                'error' => 'Telegram bot token nao configurado.',
            ];
        }

        try {
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);

            if (!$response->successful()) {
                return [
                    'attempted' => true,
                    'success' => false,
                    'error' => $response->json('description') ?? 'Telegram API error.',
                ];
            }

            return [
                'attempted' => true,
                'success' => true,
                'telegram_message_id' => $response->json('result.message_id'),
            ];
        } catch (\Throwable $e) {
            return [
                'attempted' => true,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
