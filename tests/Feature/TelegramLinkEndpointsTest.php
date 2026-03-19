<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelegramLinkEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Level::factory()->create(['id' => 1]);
    }

    public function test_generate_link_code_returns_code_and_invalidates_previous_active_code(): void
    {
        $user = User::factory()->create(['level_id' => 1]);

        $previousCode = TelegramLinkCode::create([
            'user_id' => $user->id,
            'code' => 'FICKER-111111',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/telegram/link-code');

        $response->assertOk()
            ->assertJsonPath('data.code', fn ($value) => is_string($value) && preg_match('/^FICKER-\d{6}$/', $value) === 1)
            ->assertJsonPath('data.expires_at', fn ($value) => is_string($value) && $value !== '');

        $previousCode->refresh();

        $this->assertNotNull($previousCode->used_at);
    }

    public function test_link_status_returns_not_linked_when_user_has_no_telegram_account(): void
    {
        $user = User::factory()->create(['level_id' => 1]);

        Sanctum::actingAs($user);

        $this->getJson('/api/telegram/link-status')
            ->assertOk()
            ->assertJsonPath('data.linked', false)
            ->assertJsonPath('data.account', null);
    }

    public function test_link_status_returns_active_account_data(): void
    {
        $user = User::factory()->create(['level_id' => 1]);

        $account = TelegramAccount::create([
            'user_id' => $user->id,
            'telegram_user_id' => 7377735019,
            'telegram_chat_id' => 7377735019,
            'telegram_username' => 'airton',
            'status' => TelegramAccount::STATUS_VERIFIED,
            'verified_at' => now()->subHour(),
            'last_interaction_at' => now()->subMinutes(5),
            'session_expires_at' => now()->addHours(72),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/telegram/link-status')
            ->assertOk()
            ->assertJsonPath('data.linked', true)
            ->assertJsonPath('data.account.telegram_account_id', $account->id)
            ->assertJsonPath('data.account.telegram_username', 'airton')
            ->assertJsonPath('data.account.status', TelegramAccount::STATUS_VERIFIED);
    }

    public function test_revoke_link_revokes_active_telegram_account(): void
    {
        $user = User::factory()->create(['level_id' => 1]);

        $account = TelegramAccount::create([
            'user_id' => $user->id,
            'telegram_user_id' => 7377735019,
            'telegram_chat_id' => 7377735019,
            'telegram_username' => 'airton',
            'status' => TelegramAccount::STATUS_VERIFIED,
            'verified_at' => now()->subHour(),
            'last_interaction_at' => now()->subMinutes(5),
            'session_expires_at' => now()->addHours(72),
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/telegram/link')
            ->assertOk()
            ->assertJsonPath('data.revoked', true)
            ->assertJsonPath('data.revoked_accounts_count', 1);

        $account->refresh();

        $this->assertSame(TelegramAccount::STATUS_REVOKED, $account->status);
        $this->assertNotNull($account->revoked_at);
        $this->assertNull($account->last_interaction_at);
        $this->assertNull($account->session_expires_at);
    }

    public function test_revoke_link_is_idempotent_when_no_active_account_exists(): void
    {
        $user = User::factory()->create(['level_id' => 1]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/telegram/link')
            ->assertOk()
            ->assertJsonPath('data.revoked', false)
            ->assertJsonPath('data.revoked_accounts_count', 0);
    }
}
