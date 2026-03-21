<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessageJob;
use App\Models\ConversationSession;
use App\Models\Flag;
use App\Models\Level;
use App\Models\TelegramAccount;
use App\Models\TelegramWebhookEvent;
use App\Models\Type;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramCardCreationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected TelegramAccount $account;
    protected int $updateId = 465638000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-21 12:00:00', 'America/Sao_Paulo'));

        Level::factory()->create(['id' => 1]);
        Type::factory()->create(['id' => 1, 'type_description' => 'Entrada']);
        Type::factory()->create(['id' => 2, 'type_description' => 'Saida']);
        Flag::factory()->create(['id' => 1, 'flag_description' => 'Mastercard']);
        Flag::factory()->create(['id' => 2, 'flag_description' => 'Visa']);

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

    public function test_user_can_create_card_through_chatbot_flow(): void
    {
        $startEvent = $this->processMessage('7');
        $session = $this->conversationSession();

        $this->assertSame('start_card_flow', data_get($startEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame('in_progress', data_get($startEvent->normalized_payload_json, 'card_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_CREATE_DESCRIPTION, $session->state);

        $descriptionEvent = $this->processMessage('Cartao teste isolamento');
        $session->refresh();

        $this->assertSame('in_progress', data_get($descriptionEvent->normalized_payload_json, 'card_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_CREATE_FLAG, $session->state);
        $this->assertSame('Cartao teste isolamento', $session->context(ConversationSession::CONTEXT_DRAFT . '.card_description'));

        $flagEvent = $this->processMessage('1');
        $session->refresh();

        $this->assertSame('in_progress', data_get($flagEvent->normalized_payload_json, 'card_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_CREATE_CLOSURE, $session->state);
        $this->assertSame(1, (int) $session->context(ConversationSession::CONTEXT_DRAFT . '.flag_id'));

        $closureEvent = $this->processMessage('5');
        $session->refresh();

        $this->assertSame('in_progress', data_get($closureEvent->normalized_payload_json, 'card_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_CREATE_EXPIRATION, $session->state);
        $this->assertSame(5, (int) $session->context(ConversationSession::CONTEXT_DRAFT . '.closure'));

        $expirationEvent = $this->processMessage('10');
        $session->refresh();

        $this->assertSame('in_progress', data_get($expirationEvent->normalized_payload_json, 'card_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_CREATE_CONFIRM, $session->state);
        $this->assertSame(10, (int) $session->context(ConversationSession::CONTEXT_DRAFT . '.expiration'));

        $confirmEvent = $this->processMessage('1');
        $session->refresh();

        $cardId = (int) data_get($confirmEvent->normalized_payload_json, 'card_flow.result.card_id');

        $this->assertSame('created', data_get($confirmEvent->normalized_payload_json, 'card_flow.status'));
        $this->assertSame(ConversationSession::STATE_MAIN_MENU, $session->state);

        $this->assertDatabaseHas('cards', [
            'id' => $cardId,
            'user_id' => $this->user->id,
            'flag_id' => 1,
            'card_description' => 'Cartao teste isolamento',
            'closure' => 5,
            'expiration' => 10,
        ]);
    }

    public function test_card_creation_flow_rejects_invalid_flag_and_keeps_user_in_flag_step(): void
    {
        $this->processMessage('7');
        $this->processMessage('Cartao teste isolamento');

        $event = $this->processMessage('8');
        $session = $this->conversationSession();

        $this->assertSame('validation_error', data_get($event->normalized_payload_json, 'card_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_CREATE_FLAG, $session->state);
        $this->assertSame('Cartao teste isolamento', $session->context(ConversationSession::CONTEXT_DRAFT . '.card_description'));
    }

    public function test_card_creation_flow_can_go_back_to_previous_step(): void
    {
        $this->processMessage('7');
        $this->processMessage('Cartao teste isolamento');
        $this->processMessage('1');

        $event = $this->processMessage('7');
        $session = $this->conversationSession();

        $this->assertSame('in_progress', data_get($event->normalized_payload_json, 'card_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_CREATE_FLAG, $session->state);
        $this->assertSame('Cartao teste isolamento', $session->context(ConversationSession::CONTEXT_DRAFT . '.card_description'));
        $this->assertSame(1, (int) $session->context(ConversationSession::CONTEXT_DRAFT . '.flag_id'));
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
