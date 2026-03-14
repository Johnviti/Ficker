<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

class TelegramRateLimitService
{
    public function check(int|string|null $chatId): array
    {
        $limit = (int) config('services.telegram.rate_limit_max_hits', 15);
        $windowSeconds = (int) config('services.telegram.rate_limit_window_seconds', 60);

        if (is_null($chatId) || $chatId === '') {
            return [
                'allowed' => false,
                'key' => null,
                'current_hits' => 0,
                'limit' => $limit,
                'window_seconds' => $windowSeconds,
            ];
        }

        $key = 'telegram_rate_limit:' . $chatId;

        Cache::add($key, 0, now()->addSeconds($windowSeconds));
        $hits = Cache::increment($key);

        return [
            'allowed' => $hits <= $limit,
            'key' => $key,
            'current_hits' => $hits,
            'limit' => $limit,
            'window_seconds' => $windowSeconds,
        ];
    }
}
