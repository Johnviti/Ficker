<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessageJob;
use App\Models\Card;
use App\Models\CardInvoicePayment;
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

class TelegramInvoicePaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected TelegramAccount $account;
    protected Card $card;
    protected Category $expenseCategory;
    protected int $updateId = 465637000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00', 'America/Sao_Paulo'));

        Level::factory()->create(['id' => 1]);
        Type::factory()->create(['id' => 1, 'type_description' => 'Entrada']);
        Type::factory()->create(['id' => 2, 'type_description' => 'Saida']);
        Flag::factory()->create(['id' => 1, 'flag_description' => 'Mastercard']);
        PaymentMethod::factory()->create(['id' => 1, 'payment_method_description' => 'Dinheiro']);
        PaymentMethod::factory()->create(['id' => 2, 'payment_method_description' => 'Pix']);
        PaymentMethod::factory()->create(['id' => 4, 'payment_method_description' => 'Cartao de credito']);

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

        $this->expenseCategory = Category::factory()->create([
            'id' => 40,
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Alimentacao',
        ]);

        $this->card = Card::factory()->create([
            'id' => 25,
            'user_id' => $this->user->id,
            'flag_id' => 1,
            'card_description' => 'Cartao Categoria Fatura',
            'closure' => 5,
            'expiration' => 10,
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

    public function test_user_can_pay_current_invoice_through_chatbot_flow_with_existing_category(): void
    {
        $purchase = Transaction::factory()->create([
            'id' => 102,
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra cartao mercado',
            'date' => '2026-03-02',
            'transaction_value' => 300,
            'card_id' => $this->card->id,
            'installments' => 1,
        ]);

        $installment = Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra cartao mercado 1/1',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-03-10',
            'paid_at' => null,
            'payment_transaction_id' => null,
        ]);

        $this->seedCardDetailsConversation('2026-03-10', '2026-03-05', 300);

        $startEvent = $this->processMessage('2');
        $session = $this->conversationSession();

        $this->assertSame('start_card_invoice_payment_flow', data_get($startEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame('in_progress', data_get($startEvent->normalized_payload_json, 'card_invoice_payment_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_INVOICE_PAYMENT_METHOD, $session->state);

        $paymentMethodEvent = $this->processMessage('1');
        $session->refresh();

        $this->assertSame('in_progress', data_get($paymentMethodEvent->normalized_payload_json, 'card_invoice_payment_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_INVOICE_PAYMENT_AMOUNT, $session->state);
        $this->assertSame(1, (int) $session->context(ConversationSession::CONTEXT_DRAFT . '.payment_method_id'));

        $amountEvent = $this->processMessage('300');
        $session->refresh();

        $this->assertSame('in_progress', data_get($amountEvent->normalized_payload_json, 'card_invoice_payment_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_INVOICE_PAYMENT_CATEGORY, $session->state);
        $this->assertSame(300.0, (float) $session->context(ConversationSession::CONTEXT_DRAFT . '.amount_paid'));

        $categoryEvent = $this->processMessage('2');
        $session->refresh();

        $this->assertSame('in_progress', data_get($categoryEvent->normalized_payload_json, 'card_invoice_payment_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_INVOICE_PAYMENT_CONFIRM, $session->state);
        $this->assertSame($this->expenseCategory->id, (int) $session->context(ConversationSession::CONTEXT_DRAFT . '.category_id'));

        $confirmEvent = $this->processMessage('1');
        $session->refresh();
        $installment->refresh();

        $paymentTransactionId = (int) data_get($confirmEvent->normalized_payload_json, 'card_invoice_payment_flow.result.payment_transaction_id');

        $this->assertSame('created', data_get($confirmEvent->normalized_payload_json, 'card_invoice_payment_flow.status'));
        $this->assertSame(ConversationSession::STATE_MAIN_MENU, $session->state);
        $this->assertNotNull($installment->paid_at);
        $this->assertSame($paymentTransactionId, (int) $installment->payment_transaction_id);

        $this->assertDatabaseHas('transactions', [
            'id' => $paymentTransactionId,
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - ' . $this->card->card_description,
            'transaction_value' => 300,
        ]);

        $this->assertDatabaseHas('card_invoice_payments', [
            'card_id' => $this->card->id,
            'pay_day' => '2026-03-10',
            'payment_transaction_id' => $paymentTransactionId,
            'amount_paid' => 300.00,
        ]);
    }

    public function test_user_can_pay_partial_invoice_amount_through_chatbot_flow(): void
    {
        $purchase = Transaction::factory()->create([
            'id' => 109,
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra cartao parcial',
            'date' => '2026-03-02',
            'transaction_value' => 300,
            'card_id' => $this->card->id,
            'installments' => 1,
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra cartao parcial 1/1',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-03-10',
            'paid_at' => null,
            'payment_transaction_id' => null,
        ]);

        $this->seedCardDetailsConversation('2026-03-10', '2026-03-05', 300);

        $this->processMessage('2');
        $this->processMessage('1');
        $this->processMessage('150,50');
        $this->processMessage('2');
        $confirmEvent = $this->processMessage('1');

        $paymentTransactionId = (int) data_get($confirmEvent->normalized_payload_json, 'card_invoice_payment_flow.result.payment_transaction_id');

        $this->assertDatabaseHas('transactions', [
            'id' => $paymentTransactionId,
            'transaction_value' => 150.50,
        ]);

        $this->assertDatabaseHas('card_invoice_payments', [
            'card_id' => $this->card->id,
            'pay_day' => '2026-03-10',
            'payment_transaction_id' => $paymentTransactionId,
            'amount_paid' => 150.50,
        ]);
    }

    public function test_invoice_payment_flow_is_blocked_when_invoice_is_not_closed_yet(): void
    {
        $this->seedCardDetailsConversation('2026-04-10', '2026-04-05', 300);

        $event = $this->processMessage('2');
        $session = $this->conversationSession();

        $this->assertSame('start_card_invoice_payment_flow', data_get($event->normalized_payload_json, 'intent.intent'));
        $this->assertSame('blocked', data_get($event->normalized_payload_json, 'card_invoice_payment_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_DETAILS, $session->state);

        $this->assertDatabaseMissing('transactions', [
            'user_id' => $this->user->id,
            'transaction_description' => 'Pagamento fatura - ' . $this->card->card_description,
        ]);
    }

    public function test_invoice_payment_flow_can_go_back_to_card_details_from_first_step(): void
    {
        $purchase = Transaction::factory()->create([
            'id' => 103,
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra cartao farmacia',
            'date' => '2026-03-02',
            'transaction_value' => 200,
            'card_id' => $this->card->id,
            'installments' => 1,
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra cartao farmacia 1/1',
            'installment_value' => 200,
            'card_id' => $this->card->id,
            'pay_day' => '2026-03-10',
            'paid_at' => null,
            'payment_transaction_id' => null,
        ]);

        $this->seedCardDetailsConversation('2026-03-10', '2026-03-05', 200);

        $this->processMessage('2');
        $backEvent = $this->processMessage('7');

        $session = $this->conversationSession();

        $this->assertSame('in_progress', data_get($backEvent->normalized_payload_json, 'card_invoice_payment_flow.status'));
        $this->assertSame(ConversationSession::STATE_CARD_DETAILS, $session->state);
        $this->assertSame($this->card->id, (int) $session->context(ConversationSession::CONTEXT_SELECTED_CARD_ID));
        $this->assertSame('Cartao Categoria Fatura', $session->context(ConversationSession::CONTEXT_SELECTED_CARD_DESCRIPTION));
    }

    private function seedCardDetailsConversation(string $payDay, string $closureDate, float $invoiceTotal): void
    {
        ConversationSession::create([
            'channel' => 'telegram',
            'external_chat_id' => (string) $this->account->telegram_chat_id,
            'user_id' => $this->user->id,
            'state' => ConversationSession::STATE_CARD_DETAILS,
            'context_json' => [
                ConversationSession::CONTEXT_PREVIOUS_STATE => ConversationSession::STATE_CARDS_SUMMARY,
                ConversationSession::CONTEXT_SELECTED_CARD_ID => $this->card->id,
                ConversationSession::CONTEXT_SELECTED_CARD_DESCRIPTION => $this->card->card_description,
                ConversationSession::CONTEXT_SELECTED_CARD_PAY_DAY => $payDay,
                ConversationSession::CONTEXT_SELECTED_CARD_CLOSURE_DATE => $closureDate,
                ConversationSession::CONTEXT_SELECTED_CARD_INVOICE_TOTAL => $invoiceTotal,
                ConversationSession::CONTEXT_PARENT_PAGE => 1,
            ],
            'last_message_at' => now(),
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
