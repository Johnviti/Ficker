<?php

namespace Tests\Unit\Telegram;

use App\Models\Level;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Telegram\TelegramSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TelegramSessionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Level::factory()->create(['id' => 1]);
        $this->service = app(TelegramSessionService::class);
    }

    public function test_resolve_active_account_returns_not_linked_when_payload_has_no_identifiers(): void
    {
        $result = $this->service->resolveActiveAccount([]);

        $this->assertSame('not_linked', $result['status']);
    }

    public function test_resolve_active_account_returns_active_for_verified_account_with_valid_session(): void
    {
        $user = User::factory()->create(['level_id' => 1]);
        $account = TelegramAccount::create([
            'user_id' => $user->id,
            'telegram_user_id' => 1111111111,
            'telegram_chat_id' => 2222222222,
            'telegram_username' => 'active_user',
            'status' => TelegramAccount::STATUS_VERIFIED,
            'verified_at' => now()->subHour(),
            'session_expires_at' => now()->addHours(12),
        ]);

        $result = $this->service->resolveActiveAccount([
            'telegram_chat_id' => 2222222222,
        ]);

        $this->assertSame('active', $result['status']);
        $this->assertSame($user->id, $result['user_id']);
        $this->assertSame($account->id, $result['telegram_account_id']);
    }

    public function test_resolve_active_account_returns_session_expired_for_verified_account_without_valid_session(): void
    {
        $user = User::factory()->create(['level_id' => 1]);
        $account = TelegramAccount::create([
            'user_id' => $user->id,
            'telegram_user_id' => 3333333333,
            'telegram_chat_id' => 4444444444,
            'telegram_username' => 'expired_user',
            'status' => TelegramAccount::STATUS_VERIFIED,
            'verified_at' => now()->subHour(),
            'session_expires_at' => now()->subMinute(),
        ]);

        $result = $this->service->resolveActiveAccount([
            'telegram_chat_id' => 4444444444,
        ]);

        $this->assertSame('session_expired', $result['status']);
        $this->assertSame($user->id, $result['user_id']);
        $this->assertSame($account->id, $result['telegram_account_id']);
    }

    public function test_resolve_active_account_returns_revoked_for_revoked_account(): void
    {
        $user = User::factory()->create(['level_id' => 1]);
        $account = TelegramAccount::create([
            'user_id' => $user->id,
            'telegram_user_id' => 5555555555,
            'telegram_chat_id' => 6666666666,
            'telegram_username' => 'revoked_user',
            'status' => TelegramAccount::STATUS_REVOKED,
            'verified_at' => now()->subHour(),
            'revoked_at' => now()->subMinutes(10),
        ]);

        $result = $this->service->resolveActiveAccount([
            'telegram_chat_id' => 6666666666,
        ]);

        $this->assertSame('revoked', $result['status']);
        $this->assertSame($account->id, $result['telegram_account_id']);
    }
}
