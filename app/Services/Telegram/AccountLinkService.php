<?php

namespace App\Services\Telegram;

use App\Models\TelegramLinkCode;

class AccountLinkService
{
    public function generateLinkCode(int $userId): TelegramLinkCode
    {
        TelegramLinkCode::where('user_id', $userId)
            ->active()
            ->get()
            ->each(function (TelegramLinkCode $linkCode) {
                $linkCode->markAsUsed();
            });

        return TelegramLinkCode::create([
            'user_id' => $userId,
            'code' => $this->generateUniqueCode(),
            'expires_at' => now()->addMinutes((int) config('services.telegram.link_code_ttl_minutes', 10)),
            'attempts' => 0,
        ]);
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = 'FICKER-' . random_int(100000, 999999);
        } while (TelegramLinkCode::where('code', $code)->exists());

        return $code;
    }
}
