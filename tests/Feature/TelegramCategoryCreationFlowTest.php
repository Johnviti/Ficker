<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessageJob;
use App\Models\ConversationSession;
use App\Models\Level;
use App\Models\TelegramAccount;
use App\Models\TelegramWebhookEvent;
use App\Models\Type;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramCategoryCreationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected TelegramAccount $account;
    protected int $updateId = 465640000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-21 12:00:00', 'America/Sao_Paulo'));

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

    public function test_user_can_create_category_through_chatbot_flow(): void
    {
        $startEvent = $this->processMessage('6');
        $session = $this->conversationSession();

        $this->assertSame('start_category_flow', data_get($startEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame('in_progress', data_get($startEvent->normalized_payload_json, 'category_flow.status'));
        $this->assertSame(ConversationSession::STATE_CATEGORY_CREATE_TYPE, $session->state);

        $typeEvent = $this->processMessage('2');
        $session->refresh();

        $this->assertSame('in_progress', data_get($typeEvent->normalized_payload_json, 'category_flow.status'));
        $this->assertSame(ConversationSession::STATE_CATEGORY_CREATE_DESCRIPTION, $session->state);
        $this->assertSame(2, (int) $session->context(ConversationSession::CONTEXT_DRAFT . '.type_id'));

        $descriptionEvent = $this->processMessage('Saude');
        $session->refresh();

        $this->assertSame('in_progress', data_get($descriptionEvent->normalized_payload_json, 'category_flow.status'));
        $this->assertSame(ConversationSession::STATE_CATEGORY_CREATE_CONFIRM, $session->state);
        $this->assertSame('Saude', $session->context(ConversationSession::CONTEXT_DRAFT . '.category_description'));

        $confirmEvent = $this->processMessage('1');
        $session->refresh();

        $categoryId = (int) data_get($confirmEvent->normalized_payload_json, 'category_flow.result.category.id');

        $this->assertSame('created', data_get($confirmEvent->normalized_payload_json, 'category_flow.status'));
        $this->assertSame(ConversationSession::STATE_MAIN_MENU, $session->state);

        $this->assertDatabaseHas('categories', [
            'id' => $categoryId,
            'user_id' => $this->user->id,
            'category_description' => 'Saude',
            'type_id' => 2,
        ]);
    }

    public function test_category_creation_flow_rejects_invalid_type_and_keeps_user_in_type_step(): void
    {
        $this->processMessage('6');

        $event = $this->processMessage('3');
        $session = $this->conversationSession();

        $this->assertSame('validation_error', data_get($event->normalized_payload_json, 'category_flow.status'));
        $this->assertSame(ConversationSession::STATE_CATEGORY_CREATE_TYPE, $session->state);
    }

    public function test_category_creation_flow_can_go_back_to_previous_step(): void
    {
        $this->processMessage('6');
        $this->processMessage('2');
        $this->processMessage('Saude');

        $event = $this->processMessage('7');
        $session = $this->conversationSession();

        $this->assertSame('in_progress', data_get($event->normalized_payload_json, 'category_flow.status'));
        $this->assertSame(ConversationSession::STATE_CATEGORY_CREATE_DESCRIPTION, $session->state);
        $this->assertSame(2, (int) $session->context(ConversationSession::CONTEXT_DRAFT . '.type_id'));
        $this->assertSame('Saude', $session->context(ConversationSession::CONTEXT_DRAFT . '.category_description'));
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
