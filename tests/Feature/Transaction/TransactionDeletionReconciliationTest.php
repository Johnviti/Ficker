<?php

namespace Tests\Feature\Transaction;

use App\Models\Card;
use App\Models\CardInvoicePayment;
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

class TransactionDeletionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Card $card;
    protected Category $expenseCategory;
    protected Category $invoiceCategory;

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

        $this->invoiceCategory = Category::factory()->create([
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Pagamento de fatura',
        ]);

        $this->card = Card::factory()->create([
            'user_id' => $this->user->id,
            'flag_id' => 1,
            'card_description' => 'Cartao Principal2',
            'closure' => 15,
            'expiration' => 3,
        ]);
    }

    public function test_deleting_paid_purchase_reduces_linked_invoice_payment_transaction_value(): void
    {
        $purchaseA = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra A',
            'date' => '2026-03-10',
            'transaction_value' => 300,
            'installments' => 1,
        ]);

        $purchaseB = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra B',
            'date' => '2026-03-11',
            'transaction_value' => 350,
            'installments' => 1,
        ]);

        $payment = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->invoiceCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-15',
            'transaction_value' => 650,
        ]);

        Installment::create([
            'transaction_id' => $purchaseA->id,
            'installment_description' => 'Compra A 1/1',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'paid_at' => '2026-03-15 18:07:19',
            'payment_transaction_id' => $payment->id,
        ]);

        Installment::create([
            'transaction_id' => $purchaseB->id,
            'installment_description' => 'Compra B 1/1',
            'installment_value' => 350,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'paid_at' => '2026-03-15 18:07:19',
            'payment_transaction_id' => $payment->id,
        ]);

        CardInvoicePayment::create([
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'payment_transaction_id' => $payment->id,
            'payment_method_id' => 1,
            'category_id' => $this->invoiceCategory->id,
            'amount_paid' => 650,
            'paid_at' => '2026-03-15 18:07:19',
        ]);

        $this->deleteJson("/api/transaction/{$purchaseA->id}")
            ->assertOk()
            ->assertJsonPath('data.message', 'Transacao excluida com sucesso.');

        $this->assertDatabaseMissing('transactions', [
            'id' => $purchaseA->id,
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $payment->id,
            'transaction_value' => 350,
        ]);

        $this->assertDatabaseHas('card_invoice_payments', [
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'payment_transaction_id' => $payment->id,
            'amount_paid' => 350.00,
        ]);

        $this->assertDatabaseHas('installments', [
            'transaction_id' => $purchaseB->id,
            'payment_transaction_id' => $payment->id,
        ]);
    }

    public function test_deleting_last_paid_purchase_removes_orphan_invoice_payment_transaction(): void
    {
        $purchase = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra unica',
            'date' => '2026-03-10',
            'transaction_value' => 300,
            'installments' => 1,
        ]);

        $payment = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->invoiceCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-15',
            'transaction_value' => 300,
        ]);

        Installment::create([
            'transaction_id' => $purchase->id,
            'installment_description' => 'Compra unica 1/1',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'paid_at' => '2026-03-15 18:07:19',
            'payment_transaction_id' => $payment->id,
        ]);

        CardInvoicePayment::create([
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'payment_transaction_id' => $payment->id,
            'payment_method_id' => 1,
            'category_id' => $this->invoiceCategory->id,
            'amount_paid' => 300,
            'paid_at' => '2026-03-15 18:07:19',
        ]);

        $this->deleteJson("/api/transaction/{$purchase->id}")
            ->assertOk();

        $this->assertDatabaseMissing('transactions', [
            'id' => $purchase->id,
        ]);

        $this->assertDatabaseMissing('transactions', [
            'id' => $payment->id,
        ]);

        $this->assertDatabaseMissing('card_invoice_payments', [
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'payment_transaction_id' => $payment->id,
        ]);
    }

    public function test_deleting_purchase_reconciles_multiple_partial_invoice_payments_from_latest_to_oldest(): void
    {
        $purchaseA = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra A',
            'date' => '2026-03-10',
            'transaction_value' => 300,
            'installments' => 1,
        ]);

        $purchaseB = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra B',
            'date' => '2026-03-11',
            'transaction_value' => 300,
            'installments' => 1,
        ]);

        Installment::create([
            'transaction_id' => $purchaseA->id,
            'installment_description' => 'Compra A 1/1',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        Installment::create([
            'transaction_id' => $purchaseB->id,
            'installment_description' => 'Compra B 1/1',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        $paymentA = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->invoiceCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-15',
            'transaction_value' => 200,
        ]);

        $paymentB = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->invoiceCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-16',
            'transaction_value' => 200,
        ]);

        CardInvoicePayment::create([
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'payment_transaction_id' => $paymentA->id,
            'payment_method_id' => 1,
            'category_id' => $this->invoiceCategory->id,
            'amount_paid' => 200,
            'paid_at' => '2026-03-15 18:07:19',
        ]);

        CardInvoicePayment::create([
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'payment_transaction_id' => $paymentB->id,
            'payment_method_id' => 1,
            'category_id' => $this->invoiceCategory->id,
            'amount_paid' => 200,
            'paid_at' => '2026-03-16 18:07:19',
        ]);

        $this->deleteJson("/api/transaction/{$purchaseA->id}")
            ->assertOk();

        $this->assertDatabaseHas('transactions', [
            'id' => $paymentA->id,
            'transaction_value' => 200,
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $paymentB->id,
            'transaction_value' => 100,
        ]);

        $this->assertDatabaseHas('card_invoice_payments', [
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'payment_transaction_id' => $paymentA->id,
            'amount_paid' => 200.00,
        ]);

        $this->assertDatabaseHas('card_invoice_payments', [
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'payment_transaction_id' => $paymentB->id,
            'amount_paid' => 100.00,
        ]);
    }

    public function test_deleting_purchase_marks_remaining_installment_as_paid_when_partial_payment_becomes_sufficient(): void
    {
        $purchaseA = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra A',
            'date' => '2026-03-10',
            'transaction_value' => 100,
            'installments' => 10,
            'card_id' => $this->card->id,
        ]);

        $purchaseB = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra B',
            'date' => '2026-03-10',
            'transaction_value' => 200,
            'installments' => 10,
            'card_id' => $this->card->id,
        ]);

        $remainingInstallment = Installment::create([
            'transaction_id' => $purchaseA->id,
            'installment_description' => 'Compra A 1/10',
            'installment_value' => 10,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        Installment::create([
            'transaction_id' => $purchaseB->id,
            'installment_description' => 'Compra B 1/10',
            'installment_value' => 20,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
        ]);

        $payment = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->invoiceCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-15',
            'transaction_value' => 15,
        ]);

        CardInvoicePayment::create([
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'payment_transaction_id' => $payment->id,
            'payment_method_id' => 1,
            'category_id' => $this->invoiceCategory->id,
            'amount_paid' => 15,
            'paid_at' => '2026-03-15 18:07:19',
        ]);

        $this->deleteJson("/api/transaction/{$purchaseB->id}")
            ->assertOk();

        $this->assertDatabaseHas('transactions', [
            'id' => $payment->id,
            'transaction_value' => 10,
        ]);

        $this->assertDatabaseHas('card_invoice_payments', [
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'payment_transaction_id' => $payment->id,
            'amount_paid' => 10.00,
        ]);

        $this->assertDatabaseHas('installments', [
            'id' => $remainingInstallment->id,
            'payment_transaction_id' => $payment->id,
        ]);

        $this->assertNotNull($remainingInstallment->fresh()->paid_at);
    }
}
