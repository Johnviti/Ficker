<?php

namespace Tests\Feature\Transaction;

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

class TransactionDeletionEndpointsTest extends TestCase
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

    public function test_user_can_delete_simple_transaction(): void
    {
        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Mercado bairro',
            'date' => '2026-03-10',
            'transaction_value' => 300,
            'card_id' => null,
        ]);

        $this->deleteJson("/api/transaction/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('data.message', 'Transacao excluida com sucesso.');

        $this->assertDatabaseMissing('transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_user_can_delete_credit_card_transaction_and_its_installments_when_no_payment_is_linked(): void
    {
        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'card_id' => $this->card->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra parcelada',
            'date' => '2026-03-10',
            'transaction_value' => 300,
            'installments' => 3,
        ]);

        Installment::create([
            'transaction_id' => $transaction->id,
            'installment_description' => 'Compra parcelada 1/3',
            'installment_value' => 100,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'paid_at' => null,
            'payment_transaction_id' => null,
        ]);

        Installment::create([
            'transaction_id' => $transaction->id,
            'installment_description' => 'Compra parcelada 2/3',
            'installment_value' => 100,
            'card_id' => $this->card->id,
            'pay_day' => '2026-05-03',
            'paid_at' => null,
            'payment_transaction_id' => null,
        ]);

        Installment::create([
            'transaction_id' => $transaction->id,
            'installment_description' => 'Compra parcelada 3/3',
            'installment_value' => 100,
            'card_id' => $this->card->id,
            'pay_day' => '2026-06-03',
            'paid_at' => null,
            'payment_transaction_id' => null,
        ]);

        $this->deleteJson("/api/transaction/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('data.message', 'Transacao excluida com sucesso.');

        $this->assertDatabaseMissing('transactions', [
            'id' => $transaction->id,
        ]);

        $this->assertDatabaseMissing('installments', [
            'transaction_id' => $transaction->id,
        ]);
    }

    public function test_delete_returns_not_found_for_transaction_outside_user_scope(): void
    {
        $otherUser = User::factory()->create(['level_id' => 1]);

        $transaction = Transaction::factory()->create([
            'user_id' => $otherUser->id,
            'category_id' => Category::factory()->create([
                'user_id' => $otherUser->id,
                'type_id' => 2,
                'category_description' => 'Outra categoria',
            ])->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Transacao alheia',
            'date' => '2026-03-10',
            'transaction_value' => 300,
        ]);

        $this->deleteJson("/api/transaction/{$transaction->id}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Erro: Esta transacao nao existe.');

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
        ]);
    }
}
