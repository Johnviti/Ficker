<?php

namespace App\Services\Telegram;

use App\Models\TelegramAccount;
use App\Models\TelegramLinkCode;
use Illuminate\Support\Facades\DB;

class AccountLinkService
{
    private const LINK_CODE_PATTERN = '/^FICKER-\d{6}$/';

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

    public function resolveLinkCode(string $text): array
    {
        $normalizedText = strtoupper(trim($text));

        if ($normalizedText === '') {
            return [
                'status' => 'not_a_code',
            ];
        }

        if (!preg_match(self::LINK_CODE_PATTERN, $normalizedText)) {
            return [
                'status' => 'not_a_code',
                'code' => $normalizedText,
            ];
        }

        $linkCode = TelegramLinkCode::where('code', $normalizedText)->first();

        if (!$linkCode) {
            return [
                'status' => 'not_found',
                'code' => $normalizedText,
            ];
        }

        $linkCode->incrementAttemptsCounter();

        if ($linkCode->isUsed()) {
            return [
                'status' => 'already_used',
                'code' => $linkCode->code,
                'link_code_id' => $linkCode->id,
                'link_code' => $linkCode,
            ];
        }

        if ($linkCode->isExpired()) {
            return [
                'status' => 'expired',
                'code' => $linkCode->code,
                'link_code_id' => $linkCode->id,
                'link_code' => $linkCode,
            ];
        }

        return [
            'status' => 'valid',
            'code' => $linkCode->code,
            'link_code_id' => $linkCode->id,
            'link_code' => $linkCode,
        ];
    }

    public function linkTelegramAccount(array $normalizedPayload, TelegramLinkCode $linkCode): array
    {
        $telegramUserId = $normalizedPayload['telegram_user_id'] ?? null;
        $telegramChatId = $normalizedPayload['telegram_chat_id'] ?? null;
        $telegramUsername = $normalizedPayload['telegram_username'] ?? null;
        $userId = $linkCode->user_id;

        if (is_null($telegramUserId) || is_null($telegramChatId)) {
            return [
                'status' => 'invalid_payload',
                'user_id' => $userId,
            ];
        }

        $chatConflict = TelegramAccount::active()
            ->byChatId($telegramChatId)
            ->where('user_id', '!=', $userId)
            ->first();

        if ($chatConflict) {
            return [
                'status' => 'chat_already_linked_to_other_user',
                'user_id' => $userId,
                'telegram_account_id' => $chatConflict->id,
            ];
        }

        $telegramUserConflict = TelegramAccount::active()
            ->byTelegramUserId($telegramUserId)
            ->where('user_id', '!=', $userId)
            ->first();

        if ($telegramUserConflict) {
            return [
                'status' => 'telegram_user_already_linked_to_other_user',
                'user_id' => $userId,
                'telegram_account_id' => $telegramUserConflict->id,
            ];
        }

        return DB::transaction(function () use ($linkCode, $telegramUserId, $telegramChatId, $telegramUsername, $userId) {
            $targetAccount = TelegramAccount::query()
                ->where('user_id', $userId)
                ->orWhere('telegram_chat_id', $telegramChatId)
                ->orWhere('telegram_user_id', $telegramUserId)
                ->latest('id')
                ->first();

            TelegramAccount::active()
                ->where('user_id', $userId)
                ->when($targetAccount, function ($query) use ($targetAccount) {
                    return $query->where('id', '!=', $targetAccount->id);
                })
                ->get()
                ->each(function (TelegramAccount $account) {
                    $account->revoke();
                });

            if ($targetAccount) {
                $targetAccount->update([
                    'user_id' => $userId,
                    'telegram_user_id' => $telegramUserId,
                    'telegram_chat_id' => $telegramChatId,
                    'telegram_username' => $telegramUsername,
                    'status' => TelegramAccount::STATUS_VERIFIED,
                    'verified_at' => now(),
                    'last_interaction_at' => now(),
                    'session_expires_at' => now()->addHours((int) config('services.telegram.session_ttl_hours', 72)),
                    'revoked_at' => null,
                ]);
            } else {
                $targetAccount = TelegramAccount::create([
                    'user_id' => $userId,
                    'telegram_user_id' => $telegramUserId,
                    'telegram_chat_id' => $telegramChatId,
                    'telegram_username' => $telegramUsername,
                    'status' => TelegramAccount::STATUS_VERIFIED,
                    'verified_at' => now(),
                    'last_interaction_at' => now(),
                    'session_expires_at' => now()->addHours((int) config('services.telegram.session_ttl_hours', 72)),
                ]);
            }

            $linkCode->markAsUsed();

            return [
                'status' => 'linked',
                'user_id' => $userId,
                'telegram_account_id' => $targetAccount->id,
            ];
        });
    }

    public function getActiveLinkStatus(int $userId): array
    {
        $account = TelegramAccount::query()
            ->where('user_id', $userId)
            ->latest('id')
            ->first();

        if (!$account) {
            return [
                'linked' => false,
                'account' => null,
            ];
        }

        return [
            'linked' => $account->isActive(),
            'account' => [
                'telegram_account_id' => $account->id,
                'telegram_user_id' => (string) $account->telegram_user_id,
                'telegram_chat_id' => (string) $account->telegram_chat_id,
                'telegram_username' => $account->telegram_username,
                'status' => $account->status,
                'verified_at' => $account->verified_at?->format('Y-m-d H:i:s'),
                'last_interaction_at' => $account->last_interaction_at?->format('Y-m-d H:i:s'),
                'session_expires_at' => $account->session_expires_at?->format('Y-m-d H:i:s'),
                'revoked_at' => $account->revoked_at?->format('Y-m-d H:i:s'),
            ],
        ];
    }

    public function revokeActiveLinks(int $userId): array
    {
        $activeAccounts = TelegramAccount::active()
            ->where('user_id', $userId)
            ->get();

        $revokedAccountsCount = 0;

        $activeAccounts->each(function (TelegramAccount $account) use (&$revokedAccountsCount) {
            $account->revoke();
            $revokedAccountsCount++;
        });

        return [
            'revoked' => $revokedAccountsCount > 0,
            'revoked_accounts_count' => $revokedAccountsCount,
        ];
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = 'FICKER-' . random_int(100000, 999999);
        } while (TelegramLinkCode::where('code', $code)->exists());

        return $code;
    }
}
