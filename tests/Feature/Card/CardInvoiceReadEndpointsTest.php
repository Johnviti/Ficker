<?php

namespace Tests\Feature\Card;

use App\Models\Card;
use App\Models\Category;
use App\Models\Flag;
use App\Models\Installment;
use App\Models\Level;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CardInvoiceReadEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Card $card;
    protected Category $expenseCategory;

    protected function setUp(): void
    {
        parent::setUp();

        Level::factory()->create(['id' => 1]);
        Type::factory()->create(['id' => 1, 'type_description' => 'Entrada']);
        Type::factory()->create(['id' => 2, 'type_description' => 'Saida']);
        PaymentMethod::factory()->create(['id' => 1, 'payment_method_description' => 'Dinheiro']);
        PaymentMethod::factory()->create(['id' => 4, 'payment_method_description' => 'Cartao de credito']);
        Flag::factory()->create(['id' => 1, 'flag_description' => 'Mastercard']);

        $this->user = User::factory()->create(['level_id' => 1]);
        Sanctum::actingAs($this->user);

        $this->expenseCategory = Category::factory()->create([
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Alimentacao',
        ]);

        $this->card = Card::factory()->create([
            'user_id' => $this->user->id,
            'flag_id' => 1,
            'card_description' => 'Cartao Principal2',
            'closure' => 15,
            'expiration' => 3,
        ]);
    }

    public function test_show_cards_returns_cards_with_current_invoice_value(): void
    {
        $purchase = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra mercado',
            'date' => '2026-03-10',
            'transaction_value' => 650,
            'installments' => 2,
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra mercado 1/2',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra mercado 2/2',
            'installment_value' => 350,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        $this->getJson('/api/cards')
            ->assertOk()
            ->assertJsonPath('data.cards.0.id', $this->card->id)
            ->assertJsonPath('data.cards.0.invoice', 650);
    }

    public function test_show_card_invoice_returns_zero_when_card_has_no_open_invoice(): void
    {
        $this->getJson("/api/cards/{$this->card->id}/invoice")
            ->assertOk()
            ->assertJsonPath('data.invoice', 0)
            ->assertJsonPath('data.pay_day', null);
    }

    public function test_show_card_invoice_returns_current_open_invoice_and_pay_day(): void
    {
        $purchase = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra farmacia',
            'date' => '2026-03-10',
            'transaction_value' => 300,
            'installments' => 1,
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra farmacia 1/1',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        $this->getJson("/api/cards/{$this->card->id}/invoice")
            ->assertOk()
            ->assertJsonPath('data.invoice', 300)
            ->assertJsonPath('data.pay_day', '2026-04-03');
    }

    public function test_show_invoice_installments_returns_only_open_installments_of_current_invoice(): void
    {
        $purchase = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra mercado',
            'date' => '2026-03-10',
            'transaction_value' => 950,
            'installments' => 3,
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra mercado 1/3',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra mercado 2/3',
            'installment_value' => 350,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra mercado 3/3',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-05-03',
        ]);

        $this->getJson("/api/cards/{$this->card->id}/installments")
            ->assertOk()
            ->assertJsonPath('data.pay_day', '2026-04-03')
            ->assertJsonCount(2, 'data.installments')
            ->assertJsonPath('data.installments.0.installment_description', 'Compra mercado 1/3')
            ->assertJsonPath('data.installments.1.installment_description', 'Compra mercado 2/3');
    }

    public function test_show_invoices_returns_paid_and_open_invoice_history(): void
    {
        $purchaseA = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra A',
            'date' => '2026-03-10',
            'transaction_value' => 650,
            'installments' => 2,
        ]);

        $paymentTransaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-15',
            'transaction_value' => 650,
        ]);

        Installment::create([
            'transaction_id' => $purchaseA->id,
            'installment_description' => 'Compra A 1/2',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'paid_at' => '2026-03-15 18:07:19',
            'payment_transaction_id' => $paymentTransaction->id,
        ]);

        Installment::create([
            'transaction_id' => $purchaseA->id,
            'installment_description' => 'Compra A 2/2',
            'installment_value' => 350,
            'card_id' => $this->card->id,
            'pay_day' => '2026-05-03',
        ]);

        $this->getJson("/api/cards/{$this->card->id}/invoices")
            ->assertOk()
            ->assertJsonPath('data.card_id', $this->card->id)
            ->assertJsonCount(2, 'data.invoices')
            ->assertJsonPath('data.invoices.0.pay_day', '2026-04-03')
            ->assertJsonPath('data.invoices.0.total', 300)
            ->assertJsonPath('data.invoices.0.open_total', 0)
            ->assertJsonPath('data.invoices.0.is_paid', true)
            ->assertJsonPath('data.invoices.1.pay_day', '2026-05-03')
            ->assertJsonPath('data.invoices.1.total', 350)
            ->assertJsonPath('data.invoices.1.open_total', 350)
            ->assertJsonPath('data.invoices.1.is_paid', false);
    }
}
