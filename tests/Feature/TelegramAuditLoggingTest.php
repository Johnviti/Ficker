<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessageJob;
use App\Models\AuditAccessLog;
use App\Models\ConversationSession;
use App\Models\Level;
use App\Models\TelegramAccount;
use App\Models\TelegramWebhookEvent;
use App\Models\Type;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramAuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected TelegramAccount $account;
    protected int $updateId = 465641000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-23 12:00:00', 'America/Sao_Paulo'));

        Level::factory()->create(['id' => 1]);
        Type::factory()->create(['id' => 1, 'type_description' => 'Entrada']);
        Type::factory()->create(['id' => 2, 'type_description' => 'Saida']);

        $this->user = User::factory()->create(['level_id' => 1]);

        $this->account = TelegramAccount::create([
            'user_id' => $this->user->id,
            'telegram_user_id' => 7377735019,
            'telegram_chat_id' => 7377735019,
            'telegram_username' => 'airton',
            'status' => TelegramAccount::STATUS_VERIFIED,
            'verified_at' => now()->subDay(),
            'last_interaction_at' => now()->subMinute(),
            'session_expires_at' => now()->addHours(72),
        ]);

        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => '',
            'services.telegram.rate_limit_max_hits' => 15,
            'services.telegram.rate_limit_window_seconds' => 60,
            'services.telegram.session_ttl_hours' => 72,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_get_balance_intent_creates_audit_log_entry(): void
    {
        $this->processMessage('3');

        $log = AuditAccessLog::query()
            ->where('user_id', $this->user->id)
            ->where('channel', 'telegram')
            ->where('action', 'get_balance')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame('telegram', $log->channel);
        $this->assertSame('get_balance', $log->action);
        $this->assertSame(465641000, data_get($log->metadata_json, 'update_id'));
        $this->assertSame(7377735019, data_get($log->metadata_json, 'telegram_chat_id'));
        $this->assertSame($this->account->id, data_get($log->metadata_json, 'telegram_account_id'));
    }

    public function test_starting_category_flow_creates_started_audit_log_entry(): void
    {
        $this->processMessage('6');

        $log = AuditAccessLog::query()
            ->where('user_id', $this->user->id)
            ->where('channel', 'telegram')
            ->where('action', 'create_category_started')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(465641000, data_get($log->metadata_json, 'update_id'));
        $this->assertSame(true, data_get($log->metadata_json, 'reply_success'));

        $session = $this->conversationSession();
        $this->assertSame(ConversationSession::STATE_CATEGORY_CREATE_TYPE, $session->state);
    }

    public function test_confirming_category_flow_creates_confirmed_audit_log_entry(): void
    {
        $this->processMessage('6');
        $this->processMessage('2');
        $this->processMessage('Saude');
        $this->processMessage('1');

        $log = AuditAccessLog::query()
            ->where('user_id', $this->user->id)
            ->where('channel', 'telegram')
            ->where('action', 'create_category_confirmed')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(465641003, data_get($log->metadata_json, 'update_id'));
        $this->assertSame(2, data_get($log->metadata_json, 'type_id'));
        $this->assertNotNull(data_get($log->metadata_json, 'category_id'));

        $this->assertDatabaseHas('categories', [
            'id' => data_get($log->metadata_json, 'category_id'),
            'user_id' => $this->user->id,
            'category_description' => 'Saude',
            'type_id' => 2,
        ]);
    }

    private function processMessage(string $text): TelegramWebhookEvent
    {
        $event = TelegramWebhookEvent::create([
            'update_id' => $this->updateId,
            'telegram_user_id' => $this->account->telegram_user_id,
            'telegram_chat_id' => $this->account->telegram_chat_id,
            'event_type' => 'message_received',
            'payload_json' => [
                'update_id' => $this->updateId,
                'message' => [
                    'text' => $text,
                    'from' => [
                        'id' => $this->account->telegram_user_id,
                        'username' => $this->account->telegram_username,
                    ],
                    'chat' => [
                        'id' => $this->account->telegram_chat_id,
                    ],
                ],
            ],
            'processing_status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        $this->updateId++;

        app()->call([new ProcessTelegramMessageJob($event->id), 'handle']);

        return $event->fresh();
    }

    private function conversationSession(): ConversationSession
    {
        return ConversationSession::query()
            ->where('channel', 'telegram')
            ->where('external_chat_id', (string) $this->account->telegram_chat_id)
            ->firstOrFail();
    }
}
