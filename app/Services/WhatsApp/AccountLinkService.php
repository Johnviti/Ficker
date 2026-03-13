<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppLinkCode;
use Illuminate\Support\Facades\DB;

class AccountLinkService
{
    public function generateLinkCode(int $userId): WhatsAppLinkCode
    {
        WhatsAppLinkCode::where('user_id', $userId)
            ->active()
            ->get()
            ->each(function (WhatsAppLinkCode $linkCode) {
                $linkCode->markAsUsed();
            });

        return WhatsAppLinkCode::create([
            'user_id' => $userId,
            'code' => $this->generateUniqueCode(),
            'expires_at' => now()->addMinutes((int) config('services.whatsapp.link_code_ttl_minutes', 10)),
            'attempts' => 0,
        ]);
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = 'FICKER-' . random_int(100000, 999999);
        } while (WhatsAppLinkCode::where('code', $code)->exists());

        return $code;
    }

    public function tryLinkPhone(string $phone, string $messageText): array
    {
        $phone = $this->normalizePhone($phone);
        $code = $this->extractCodeCandidate($messageText);

        if (is_null($phone)) {
            return [
                'status' => 'invalid_phone',
                'linked' => false,
            ];
        }

        if (is_null($code)) {
            return [
                'status' => 'not_link_code',
                'linked' => false,
            ];
        }

        $linkCode = WhatsAppLinkCode::where('code', $code)->first();

        if (!$linkCode) {
            return [
                'status' => 'code_not_found',
                'linked' => false,
                'code' => $code,
            ];
        }

        if ($linkCode->isUsed()) {
            $linkCode->incrementAttemptsCounter();

            return [
                'status' => 'code_already_used',
                'linked' => false,
                'code' => $code,
            ];
        }

        if ($linkCode->isExpired()) {
            $linkCode->incrementAttemptsCounter();

            return [
                'status' => 'code_expired',
                'linked' => false,
                'code' => $code,
            ];
        }

        $activeAccountForPhone = WhatsAppAccount::active()
            ->byPhone($phone)
            ->first();

        if ($activeAccountForPhone && (int) $activeAccountForPhone->user_id !== (int) $linkCode->user_id) {
            $linkCode->incrementAttemptsCounter();

            return [
                'status' => 'phone_already_linked_to_another_user',
                'linked' => false,
                'code' => $code,
            ];
        }

        DB::transaction(function () use ($linkCode, $phone) {
            WhatsAppAccount::where('user_id', $linkCode->user_id)
                ->active()
                ->get()
                ->each(function (WhatsAppAccount $account) {
                    $account->revoke();
                });

            WhatsAppAccount::updateOrCreate(
                ['phone_e164' => $phone],
                [
                    'user_id' => $linkCode->user_id,
                    'provider' => (string) config('services.whatsapp.provider', 'meta'),
                    'status' => WhatsAppAccount::STATUS_VERIFIED,
                    'verified_at' => now(),
                    'revoked_at' => null,
                ]
            );

            $linkCode->markAsUsed();
        });

        return [
            'status' => 'linked',
            'linked' => true,
            'code' => $code,
            'phone_e164' => $phone,
            'user_id' => $linkCode->user_id,
        ];
    }

    private function extractCodeCandidate(string $messageText): ?string
    {
        $messageText = strtoupper(trim($messageText));

        if (preg_match('/^(FICKER-\d{6})$/', $messageText, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        return str_starts_with($digits, '55')
            ? '+' . $digits
            : '+55' . $digits;
    }
}
