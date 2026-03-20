<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessageJob;
use App\Models\Card;
use App\Models\Category;
use App\Models\ConversationSession;
use App\Models\Flag;
use App\Models\Installment;
use App\Models\Level;
use App\Models\PaymentMethod;
use App\Models\TelegramAccount;
use App\Models\TelegramWebhookEvent;
use App\Models\Transaction;
use App\Models\Type;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramConversationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected TelegramAccount $account;
    protected int $updateId = 465636000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00', 'America/Sao_Paulo'));

        Level::factory()->create(['id' => 1]);
        Type::factory()->create(['id' => 1, 'type_description' => 'Entrada']);
        Type::factory()->create(['id' => 2, 'type_description' => 'Saida']);
        Flag::factory()->create(['id' => 1, 'flag_description' => 'Mastercard']);
        PaymentMethod::factory()->create(['id' => 1, 'payment_method_description' => 'Dinheiro']);

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

    public function test_cards_navigation_moves_between_summary_details_and_back(): void
    {
        $expenseCategory = Category::factory()->create([
            'id' => 40,
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Alimentacao',
        ]);

        $card = Card::factory()->create([
            'id' => 25,
            'user_id' => $this->user->id,
            'flag_id' => 1,
            'card_description' => 'Cartao Categoria Fatura',
            'closure' => 5,
            'expiration' => 10,
        ]);

        $transaction = Transaction::factory()->create([
            'id' => 102,
            'user_id' => $this->user->id,
            'category_id' => $expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Compra cartao mercado',
            'date' => '2026-03-12',
            'transaction_value' => 300,
            'card_id' => $card->id,
            'installments' => 1,
        ]);

        Installment::create([
            'transaction_id' => $transaction->id,
            'installment_description' => 'Compra 1/1',
            'installment_value' => 300,
            'card_id' => $card->id,
            'pay_day' => '2026-04-10',
            'paid_at' => null,
            'payment_transaction_id' => null,
        ]);

        $firstEvent = $this->processMessage('1');

        $session = $this->conversationSession();

        $this->assertSame(TelegramWebhookEvent::STATUS_PROCESSED, $firstEvent->processing_status);
        $this->assertSame('cards_summary', data_get($firstEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame(ConversationSession::STATE_CARDS_SUMMARY, $session->state);
        $this->assertSame(25, (int) data_get($session->context_json, 'card_options.1.id'));

        $secondEvent = $this->processMessage('1');

        $session->refresh();

        $this->assertSame('select_card_details', data_get($secondEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame(ConversationSession::STATE_CARD_DETAILS, $session->state);
        $this->assertSame(25, (int) $session->context(ConversationSession::CONTEXT_SELECTED_CARD_ID));
        $this->assertSame('Cartao Categoria Fatura', $session->context(ConversationSession::CONTEXT_SELECTED_CARD_DESCRIPTION));

        $thirdEvent = $this->processMessage('7');

        $session->refresh();

        $this->assertSame('go_back', data_get($thirdEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame(ConversationSession::STATE_CARDS_SUMMARY, $session->state);
    }

    public function test_card_details_rejects_global_numeric_shortcuts_inside_submenu(): void
    {
        $card = Card::factory()->create([
            'id' => 25,
            'user_id' => $this->user->id,
            'flag_id' => 1,
            'card_description' => 'Cartao Categoria Fatura',
            'closure' => 5,
            'expiration' => 10,
        ]);

        $session = ConversationSession::create([
            'channel' => 'telegram',
            'external_chat_id' => (string) $this->account->telegram_chat_id,
            'user_id' => $this->user->id,
            'state' => ConversationSession::STATE_CARD_DETAILS,
            'context_json' => [
                ConversationSession::CONTEXT_PREVIOUS_STATE => ConversationSession::STATE_CARDS_SUMMARY,
                ConversationSession::CONTEXT_SELECTED_CARD_ID => $card->id,
                ConversationSession::CONTEXT_SELECTED_CARD_DESCRIPTION => $card->card_description,
                ConversationSession::CONTEXT_PARENT_PAGE => 1,
            ],
            'last_message_at' => now(),
        ]);

        $event = $this->processMessage('3');

        $session->refresh();

        $this->assertSame(TelegramWebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame('unknown', data_get($event->normalized_payload_json, 'intent.intent'));
        $this->assertSame(ConversationSession::STATE_CARD_DETAILS, $session->state);
        $this->assertSame($card->id, (int) $session->context(ConversationSession::CONTEXT_SELECTED_CARD_ID));
    }

    public function test_expense_wizard_consumes_numeric_inputs_locally_and_keeps_category_validation_isolated(): void
    {
        Category::factory()->create([
            'id' => 40,
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Alimentacao',
        ]);

        Category::factory()->create([
            'id' => 46,
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Categoria sprint 7 saida',
        ]);

        Category::factory()->create([
            'id' => 42,
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Pagamento de fatura',
        ]);

        $startEvent = $this->processMessage('5');

        $session = $this->conversationSession();

        $this->assertSame('start_expense_flow', data_get($startEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame(ConversationSession::STATE_TRANSACTION_EXPENSE_VALUE, $session->state);

        $valueEvent = $this->processMessage('3');

        $session->refresh();

        $this->assertSame('in_progress', data_get($valueEvent->normalized_payload_json, 'transaction_flow.status'));
        $this->assertSame(ConversationSession::STATE_TRANSACTION_EXPENSE_DESCRIPTION, $session->state);
        $this->assertSame(3.0, (float) $session->context(ConversationSession::CONTEXT_DRAFT . '.transaction_value'));

        $descriptionEvent = $this->processMessage('4');

        $session->refresh();

        $this->assertSame('in_progress', data_get($descriptionEvent->normalized_payload_json, 'transaction_flow.status'));
        $this->assertSame(ConversationSession::STATE_TRANSACTION_EXPENSE_CATEGORY, $session->state);
        $this->assertSame('4', $session->context(ConversationSession::CONTEXT_DRAFT . '.transaction_description'));

        $categoryEvent = $this->processMessage('4');

        $session->refresh();

        $this->assertSame('validation_error', data_get($categoryEvent->normalized_payload_json, 'transaction_flow.status'));
        $this->assertSame(ConversationSession::STATE_TRANSACTION_EXPENSE_CATEGORY, $session->state);
        $this->assertSame('expense', $session->context(ConversationSession::CONTEXT_FLOW));
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
