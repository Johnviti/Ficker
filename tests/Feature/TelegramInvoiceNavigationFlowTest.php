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

class TelegramInvoiceNavigationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected TelegramAccount $account;
    protected Card $card;
    protected Category $expenseCategory;
    protected int $updateId = 465639000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-21 12:00:00', 'America/Sao_Paulo'));

        Level::factory()->create(['id' => 1]);
        Type::factory()->create(['id' => 1, 'type_description' => 'Entrada']);
        Type::factory()->create(['id' => 2, 'type_description' => 'Saida']);
        Flag::factory()->create(['id' => 1, 'flag_description' => 'Mastercard']);
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

    public function test_user_can_navigate_from_card_details_to_invoice_items_and_back(): void
    {
        $this->seedInvoiceTransactions('2026-03-10', 2);
        $this->seedInvoiceTransactions('2026-04-10', 1);
        $this->seedCardDetailsConversation('2026-03-10', '2026-03-05', 200);

        $invoicesEvent = $this->processMessage('1');
        $session = $this->conversationSession();

        $this->assertSame('card_invoices', data_get($invoicesEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame(ConversationSession::STATE_CARD_INVOICES, $session->state);
        $this->assertSame('2026-03-10', data_get($session->context_json, 'invoice_options.1.pay_day'));
        $this->assertSame('2026-04-10', data_get($session->context_json, 'invoice_options.2.pay_day'));

        $itemsEvent = $this->processMessage('1');
        $session->refresh();

        $this->assertSame('select_card_invoice_items', data_get($itemsEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame(ConversationSession::STATE_CARD_INVOICE_ITEMS, $session->state);
        $this->assertSame('2026-03-10', $session->context(ConversationSession::CONTEXT_SELECTED_INVOICE_PAY_DAY));
        $this->assertSame(200.0, (float) $session->context(ConversationSession::CONTEXT_SELECTED_INVOICE_TOTAL));

        $backToInvoicesEvent = $this->processMessage('7');
        $session->refresh();

        $this->assertSame('go_back', data_get($backToInvoicesEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame(ConversationSession::STATE_CARD_INVOICES, $session->state);

        $backToDetailsEvent = $this->processMessage('7');
        $session->refresh();

        $this->assertSame('go_back', data_get($backToDetailsEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame(ConversationSession::STATE_CARD_DETAILS, $session->state);
        $this->assertSame($this->card->id, (int) $session->context(ConversationSession::CONTEXT_SELECTED_CARD_ID));
    }

    public function test_invoice_items_pagination_moves_between_pages(): void
    {
        $this->seedInvoiceTransactions('2026-03-10', 6);
        $this->seedCardDetailsConversation('2026-03-10', '2026-03-05', 600);

        $this->processMessage('1');
        $this->processMessage('1');

        $session = $this->conversationSession();
        $this->assertSame(ConversationSession::STATE_CARD_INVOICE_ITEMS, $session->state);
        $this->assertSame(1, (int) $session->context(ConversationSession::CONTEXT_PAGE));

        $nextPageEvent = $this->processMessage('6');
        $session->refresh();

        $this->assertSame('card_invoice_items_next_page', data_get($nextPageEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame(ConversationSession::STATE_CARD_INVOICE_ITEMS, $session->state);
        $this->assertSame(2, (int) $session->context(ConversationSession::CONTEXT_PAGE));

        $previousPageEvent = $this->processMessage('5');
        $session->refresh();

        $this->assertSame('card_invoice_items_previous_page', data_get($previousPageEvent->normalized_payload_json, 'intent.intent'));
        $this->assertSame(ConversationSession::STATE_CARD_INVOICE_ITEMS, $session->state);
        $this->assertSame(1, (int) $session->context(ConversationSession::CONTEXT_PAGE));
    }

    private function seedInvoiceTransactions(string $payDay, int $itemsCount): void
    {
        for ($i = 1; $i <= $itemsCount; $i++) {
            $transaction = Transaction::factory()->create([
                'user_id' => $this->user->id,
                'category_id' => $this->expenseCategory->id,
                'type_id' => 2,
                'payment_method_id' => 4,
                'transaction_description' => 'Compra ' . $payDay . ' ' . $i,
                'date' => '2026-03-01',
                'transaction_value' => 100,
                'card_id' => $this->card->id,
                'installments' => 1,
            ]);

            Installment::create([
                'transaction_id' => $transaction->id,
                'installment_description' => 'Compra ' . $payDay . ' ' . $i . ' 1/1',
                'installment_value' => 100,
                'card_id' => $this->card->id,
                'pay_day' => $payDay,
                'paid_at' => null,
                'payment_transaction_id' => null,
            ]);
        }
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
