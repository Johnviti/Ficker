<?php

namespace Tests\Unit\Telegram;

use App\Models\Level;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkCode;
use App\Models\User;
use App\Services\Telegram\AccountLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AccountLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Level::factory()->create(['id' => 1]);
        $this->service = app(AccountLinkService::class);
    }

    public function test_generate_link_code_invalidates_previous_active_code(): void
    {
        $user = User::factory()->create(['level_id' => 1]);

        $firstCode = TelegramLinkCode::create([
            'user_id' => $user->id,
            'code' => 'FICKER-111111',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $newCode = $this->service->generateLinkCode($user->id);

        $firstCode->refresh();

        $this->assertNotNull($firstCode->used_at);
        $this->assertNotSame($firstCode->code, $newCode->code);
        $this->assertSame($user->id, $newCode->user_id);
    }

    public function test_link_telegram_account_rejects_chat_conflict_with_other_user(): void
    {
        $userA = User::factory()->create(['level_id' => 1]);
        $userB = User::factory()->create(['level_id' => 1]);

        TelegramAccount::create([
            'user_id' => $userA->id,
            'telegram_user_id' => 1111111111,
            'telegram_chat_id' => 2222222222,
            'telegram_username' => 'user_a',
            'status' => TelegramAccount::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        $linkCode = TelegramLinkCode::create([
            'user_id' => $userB->id,
            'code' => 'FICKER-222222',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $result = $this->service->linkTelegramAccount([
            'telegram_user_id' => 3333333333,
            'telegram_chat_id' => 2222222222,
            'telegram_username' => 'user_b',
        ], $linkCode);

        $this->assertSame('chat_already_linked_to_other_user', $result['status']);
        $this->assertSame($userB->id, $result['user_id']);
    }

    public function test_link_telegram_account_rejects_telegram_user_conflict_with_other_user(): void
    {
        $userA = User::factory()->create(['level_id' => 1]);
        $userB = User::factory()->create(['level_id' => 1]);

        TelegramAccount::create([
            'user_id' => $userA->id,
            'telegram_user_id' => 4444444444,
            'telegram_chat_id' => 5555555555,
            'telegram_username' => 'user_a',
            'status' => TelegramAccount::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        $linkCode = TelegramLinkCode::create([
            'user_id' => $userB->id,
            'code' => 'FICKER-333333',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $result = $this->service->linkTelegramAccount([
            'telegram_user_id' => 4444444444,
            'telegram_chat_id' => 6666666666,
            'telegram_username' => 'user_b',
        ], $linkCode);

        $this->assertSame('telegram_user_already_linked_to_other_user', $result['status']);
        $this->assertSame($userB->id, $result['user_id']);
    }

    public function test_link_telegram_account_reuses_existing_account_for_same_user_and_new_chat(): void
    {
        $user = User::factory()->create(['level_id' => 1]);

        $existingAccount = TelegramAccount::create([
            'user_id' => $user->id,
            'telegram_user_id' => 7777777777,
            'telegram_chat_id' => 8888888888,
            'telegram_username' => 'old_username',
            'status' => TelegramAccount::STATUS_VERIFIED,
            'verified_at' => now()->subDay(),
            'session_expires_at' => now()->subHour(),
        ]);

        $linkCode = TelegramLinkCode::create([
            'user_id' => $user->id,
            'code' => 'FICKER-444444',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $result = $this->service->linkTelegramAccount([
            'telegram_user_id' => 9999999999,
            'telegram_chat_id' => 1010101010,
            'telegram_username' => 'new_username',
        ], $linkCode);

        $existingAccount->refresh();
        $linkCode->refresh();

        $this->assertSame('linked', $result['status']);
        $this->assertSame($existingAccount->id, $result['telegram_account_id']);
        $this->assertSame(9999999999, (int) $existingAccount->telegram_user_id);
        $this->assertSame(1010101010, (int) $existingAccount->telegram_chat_id);
        $this->assertSame('new_username', $existingAccount->telegram_username);
        $this->assertSame(TelegramAccount::STATUS_VERIFIED, $existingAccount->status);
        $this->assertNull($existingAccount->revoked_at);
        $this->assertNotNull($linkCode->used_at);
    }
}
