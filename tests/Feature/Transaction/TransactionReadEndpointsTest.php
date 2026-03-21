<?php

namespace Tests\Feature\Transaction;

use App\Models\Card;
use App\Models\Category;
use App\Models\Flag;
use App\Models\Level;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionReadEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Card $card;
    protected Category $incomeCategory;
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

        $this->incomeCategory = Category::factory()->create([
            'id' => 41,
            'user_id' => $this->user->id,
            'type_id' => 1,
            'category_description' => 'Salario',
        ]);

        $this->expenseCategory = Category::factory()->create([
            'id' => 40,
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Alimentacao',
        ]);

        $this->invoiceCategory = Category::factory()->create([
            'id' => 42,
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Pagamento de fatura',
        ]);

        $this->card = Card::factory()->create([
            'id' => 25,
            'user_id' => $this->user->id,
            'flag_id' => 1,
            'card_description' => 'Cartao Principal2',
            'closure' => 5,
            'expiration' => 10,
        ]);
    }

    public function test_show_transactions_returns_scoped_transactions_with_presentation_flags(): void
    {
        $income = Transaction::factory()->create([
            'id' => 100,
            'user_id' => $this->user->id,
            'category_id' => $this->incomeCategory->id,
            'type_id' => 1,
            'payment_method_id' => 1,
            'transaction_description' => 'Salario empresa',
            'date' => '2026-03-05',
            'transaction_value' => 5000,
            'card_id' => null,
        ]);

        $expense = Transaction::factory()->create([
            'id' => 101,
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Mercado bairro',
            'date' => '2026-03-10',
            'transaction_value' => 300,
            'card_id' => null,
        ]);

        $creditPurchase = Transaction::factory()->create([
            'id' => 102,
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra cartao mercado',
            'date' => '2026-03-12',
            'transaction_value' => 600,
            'card_id' => $this->card->id,
        ]);

        $invoicePayment = Transaction::factory()->create([
            'id' => 103,
            'user_id' => $this->user->id,
            'category_id' => $this->invoiceCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-15',
            'transaction_value' => 600,
            'card_id' => null,
        ]);

        Transaction::factory()->create([
            'id' => 104,
            'user_id' => User::factory()->create(['level_id' => 1])->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Transacao de outro usuario',
            'date' => '2026-03-20',
            'transaction_value' => 999,
            'card_id' => null,
        ]);

        $response = $this->getJson('/api/transaction/all');

        $response->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('most_expensive', 600)
            ->assertJsonCount(4, 'data.transactions')
            ->assertJsonPath('data.transactions.0.id', $invoicePayment->id)
            ->assertJsonPath('data.transactions.0.is_invoice_payment', true)
            ->assertJsonPath('data.transactions.0.affects_real_spending', true)
            ->assertJsonPath('data.transactions.1.id', $creditPurchase->id)
            ->assertJsonPath('data.transactions.1.is_credit_card_purchase', true)
            ->assertJsonPath('data.transactions.1.affects_real_spending', false)
            ->assertJsonPath('data.transactions.2.id', $expense->id)
            ->assertJsonPath('data.transactions.2.is_credit_card_purchase', false)
            ->assertJsonPath('data.transactions.2.is_invoice_payment', false)
            ->assertJsonPath('data.transactions.2.affects_real_spending', true)
            ->assertJsonPath('data.transactions.3.id', $income->id)
            ->assertJsonPath('data.transactions.3.affects_real_spending', false);
    }

    public function test_show_transactions_by_type_returns_only_requested_type_with_flags(): void
    {
        Transaction::factory()->create([
            'id' => 110,
            'user_id' => $this->user->id,
            'category_id' => $this->incomeCategory->id,
            'type_id' => 1,
            'payment_method_id' => 1,
            'transaction_description' => 'Salario empresa',
            'date' => '2026-03-05',
            'transaction_value' => 5000,
        ]);

        $expense = Transaction::factory()->create([
            'id' => 111,
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Mercado bairro',
            'date' => '2026-03-10',
            'transaction_value' => 300,
        ]);

        $invoicePayment = Transaction::factory()->create([
            'id' => 112,
            'user_id' => $this->user->id,
            'category_id' => $this->invoiceCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-15',
            'transaction_value' => 600,
        ]);

        $response = $this->getJson('/api/transaction/type/2');

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data.transactions')
            ->assertJsonPath('data.transactions.0.id', $invoicePayment->id)
            ->assertJsonPath('data.transactions.0.is_invoice_payment', true)
            ->assertJsonPath('data.transactions.1.id', $expense->id)
            ->assertJsonPath('data.transactions.1.is_invoice_payment', false);
    }

    public function test_show_transactions_by_card_returns_only_card_transactions_with_credit_flag(): void
    {
        $cardTransaction = Transaction::factory()->create([
            'id' => 120,
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra cartao mercado',
            'date' => '2026-03-12',
            'transaction_value' => 600,
            'card_id' => $this->card->id,
        ]);

        Transaction::factory()->create([
            'id' => 121,
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra em outro cartao',
            'date' => '2026-03-13',
            'transaction_value' => 700,
            'card_id' => Card::factory()->create([
                'user_id' => $this->user->id,
                'flag_id' => 1,
                'card_description' => 'Cartao Secundario',
                'closure' => 8,
                'expiration' => 12,
            ])->id,
        ]);

        $response = $this->getJson('/api/transaction/card/' . $this->card->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonPath('data.transactions.0.id', $cardTransaction->id)
            ->assertJsonPath('data.transactions.0.is_credit_card_purchase', true)
            ->assertJsonPath('data.transactions.0.affects_real_spending', false);
    }

    public function test_show_transaction_returns_single_scoped_transaction_with_flags(): void
    {
        $transaction = Transaction::factory()->create([
            'id' => 130,
            'user_id' => $this->user->id,
            'category_id' => $this->invoiceCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-15',
            'transaction_value' => 600,
            'card_id' => null,
        ]);

        $response = $this->getJson('/api/transaction/' . $transaction->id);

        $response->assertOk()
            ->assertJsonPath('data.transaction.id', $transaction->id)
            ->assertJsonPath('data.transaction.category_description', 'Pagamento de fatura')
            ->assertJsonPath('data.transaction.is_invoice_payment', true)
            ->assertJsonPath('data.transaction.affects_real_spending', true)
            ->assertJsonPath('data.transaction.is_credit_card_purchase', false);
    }
}
