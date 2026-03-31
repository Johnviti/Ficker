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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CardInvoicePaymentEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Card $card;
    protected Category $expenseCategory;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00', 'America/Sao_Paulo'));

        Level::factory()->create(['id' => 1]);
        Type::factory()->create(['id' => 1, 'type_description' => 'Entrada']);
        Type::factory()->create(['id' => 2, 'type_description' => 'Saida']);
        PaymentMethod::factory()->create(['id' => 1, 'payment_method_description' => 'Dinheiro']);
        PaymentMethod::factory()->create(['id' => 2, 'payment_method_description' => 'Pix']);
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_user_can_pay_invoice_by_pay_day_with_selected_existing_category(): void
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

        $response = $this->postJson("/api/cards/{$this->card->id}/invoices/2026-04-03/pay", [
            'payment_method_id' => 1,
            'category_id' => $this->expenseCategory->id,
            'date' => '2026-03-20',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.message', 'Fatura paga com sucesso.')
            ->assertJsonPath('data.card_id', $this->card->id)
            ->assertJsonPath('data.pay_day', '2026-04-03')
            ->assertJsonPath('data.invoice_value', 650)
            ->assertJsonPath('data.amount_paid', 650)
            ->assertJsonPath('data.invoice_total', 650)
            ->assertJsonPath('data.paid_total', 650)
            ->assertJsonPath('data.open_total', 0)
            ->assertJsonPath('data.status', 'paga')
            ->assertJsonPath('data.payment_transaction.category_id', $this->expenseCategory->id)
            ->assertJsonPath('data.payment_transaction.payment_method_id', 1);

        $paymentTransactionId = $response->json('data.payment_transaction.id');

        $this->assertDatabaseHas('transactions', [
            'id' => $paymentTransactionId,
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'transaction_value' => 650,
        ]);

        $this->assertSame(
            2,
            Installment::query()
                ->where('card_id', $this->card->id)
                ->whereDate('pay_day', '2026-04-03')
                ->whereNotNull('paid_at')
                ->where('payment_transaction_id', $paymentTransactionId)
                ->count()
        );

        $this->assertDatabaseHas('card_invoice_payments', [
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'payment_transaction_id' => $paymentTransactionId,
            'amount_paid' => 650.00,
        ]);
    }

    public function test_user_cannot_pay_invoice_before_closure_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00', 'America/Sao_Paulo'));

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

        $response = $this->postJson("/api/cards/{$this->card->id}/invoices/2026-04-03/pay", [
            'payment_method_id' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'A fatura ainda nao fechou. Voce so pode pagar apos o fechamento.')
            ->assertJsonPath('errors.invoice_closure_date.0', '2026-03-15');

        $this->assertDatabaseMissing('transactions', [
            'user_id' => $this->user->id,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'transaction_value' => 300,
        ]);
    }

    public function test_pay_next_invoice_creates_default_invoice_payment_category_when_missing(): void
    {
        $purchase = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra padaria',
            'date' => '2026-03-10',
            'transaction_value' => 200,
            'installments' => 1,
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra padaria 1/1',
            'installment_value' => 200,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        $response = $this->postJson("/api/cards/{$this->card->id}/pay-invoice", [
            'payment_method_id' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.invoice_value', 200)
            ->assertJsonPath('data.amount_paid', 200)
            ->assertJsonPath('data.payment_transaction.payment_method_id', 2);

        $this->assertDatabaseHas('categories', [
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Pagamento de fatura',
        ]);
    }

    public function test_user_cannot_pay_invoice_that_is_already_paid(): void
    {
        $purchase = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra quitada',
            'date' => '2026-03-10',
            'transaction_value' => 300,
            'installments' => 1,
        ]);

        $paymentTransaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-15',
            'transaction_value' => 300,
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra quitada 1/1',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'paid_at' => '2026-03-15 18:07:19',
            'payment_transaction_id' => $paymentTransaction->id,
        ]);

        $this->postJson("/api/cards/{$this->card->id}/invoices/2026-04-03/pay", [
            'payment_method_id' => 1,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Esta fatura nao possui parcelas em aberto (ja paga ou inexistente).')
            ->assertJsonPath('errors.invoice_pay_day.0', '2026-04-03');
    }

    public function test_user_can_pay_invoice_creating_a_new_output_category(): void
    {
        $purchase = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra viagem',
            'date' => '2026-03-10',
            'transaction_value' => 200,
            'installments' => 1,
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra viagem 1/1',
            'installment_value' => 200,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        $response = $this->postJson("/api/cards/{$this->card->id}/invoices/2026-04-03/pay", [
            'payment_method_id' => 2,
            'category_id' => 0,
            'category_description' => 'Pagamento viagem cartao',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.invoice_value', 200)
            ->assertJsonPath('data.amount_paid', 200)
            ->assertJsonPath('data.payment_transaction.payment_method_id', 2);

        $this->assertDatabaseHas('categories', [
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Pagamento viagem cartao',
        ]);
    }

    public function test_user_can_pay_invoice_partially_and_leave_open_balance(): void
    {
        $purchase = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra parcial',
            'date' => '2026-03-10',
            'transaction_value' => 500,
            'installments' => 2,
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra parcial 1/2',
            'installment_value' => 200,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra parcial 2/2',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        $response = $this->postJson("/api/cards/{$this->card->id}/invoices/2026-04-03/pay", [
            'payment_method_id' => 1,
            'category_id' => $this->expenseCategory->id,
            'amount_paid' => 200,
            'date' => '2026-03-20',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.invoice_value', 200)
            ->assertJsonPath('data.amount_paid', 200)
            ->assertJsonPath('data.invoice_total', 500)
            ->assertJsonPath('data.paid_total', 200)
            ->assertJsonPath('data.open_total', 300)
            ->assertJsonPath('data.status', 'parcialmente_paga');

        $paymentTransactionId = $response->json('data.payment_transaction.id');

        $this->assertDatabaseHas('transactions', [
            'id' => $paymentTransactionId,
            'transaction_value' => 200,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
        ]);

        $this->assertDatabaseHas('card_invoice_payments', [
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'payment_transaction_id' => $paymentTransactionId,
            'amount_paid' => 200.00,
        ]);

        $this->assertSame(
            0,
            Installment::query()
                ->where('card_id', $this->card->id)
                ->whereDate('pay_day', '2026-04-03')
                ->whereNotNull('paid_at')
                ->count()
        );
    }

    public function test_user_cannot_pay_more_than_open_balance(): void
    {
        $purchase = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra parcial',
            'date' => '2026-03-10',
            'transaction_value' => 500,
            'installments' => 2,
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra parcial 1/2',
            'installment_value' => 200,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra parcial 2/2',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        $this->postJson("/api/cards/{$this->card->id}/invoices/2026-04-03/pay", [
            'payment_method_id' => 1,
            'category_id' => $this->expenseCategory->id,
            'amount_paid' => 600,
            'date' => '2026-03-20',
        ])->assertStatus(422)
            ->assertJsonPath('errors.amount_paid.0', 'O valor pago deve ser maior que zero e menor ou igual ao saldo em aberto da fatura.');
    }
}
