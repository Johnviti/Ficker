<?php

namespace App\Services\Telegram;

use App\Models\TelegramAccount;

class TelegramSessionService
{
    public function resolveActiveAccount(array $normalizedPayload): array
    {
        $chatId = $normalizedPayload['telegram_chat_id'] ?? null;
        $telegramUserId = $normalizedPayload['telegram_user_id'] ?? null;

        if (is_null($chatId) && is_null($telegramUserId)) {
            return [
                'status' => 'not_linked',
            ];
        }

        $account = TelegramAccount::query()
            ->when(!is_null($chatId), function ($query) use ($chatId) {
                return $query->where('telegram_chat_id', $chatId);
            })
            ->when(is_null($chatId) && !is_null($telegramUserId), function ($query) use ($telegramUserId) {
                return $query->where('telegram_user_id', $telegramUserId);
            })
            ->latest('id')
            ->first();

        if (!$account) {
            return [
                'status' => 'not_linked',
            ];
        }

        if (!$account->isActive()) {
            return [
                'status' => 'revoked',
                'telegram_account_id' => $account->id,
                'account' => $account,
            ];
        }

        if (!$account->isSessionActive()) {
            return [
                'status' => 'session_expired',
                'telegram_account_id' => $account->id,
                'user_id' => $account->user_id,
                'account' => $account,
            ];
        }

        return [
            'status' => 'active',
            'telegram_account_id' => $account->id,
            'user_id' => $account->user_id,
            'account' => $account,
        ];
    }

    public function refreshSession(TelegramAccount $account): void
    {
        $account->refreshSession();
    }
}
