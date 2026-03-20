<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Level;
use App\Models\PaymentMethod;
use App\Models\Spending;
use App\Models\Transaction;
use App\Models\Type;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BalanceEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Category $incomeCategory;
    protected Category $expenseCategory;
    protected Category $invoiceCategory;

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

        $this->user = User::factory()->create(['level_id' => 1]);
        Sanctum::actingAs($this->user);

        $this->incomeCategory = Category::factory()->create([
            'user_id' => $this->user->id,
            'type_id' => 1,
            'category_description' => 'Salario',
        ]);

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_balance_returns_zero_planned_spending_when_user_has_no_spending_record(): void
    {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->incomeCategory->id,
            'type_id' => 1,
            'payment_method_id' => 1,
            'transaction_description' => 'Salario',
            'date' => '2026-03-05',
            'transaction_value' => 5000,
        ]);

        $this->getJson('/api/balance')
            ->assertOk()
            ->assertJsonPath('finances.planned_spending', 0)
            ->assertJsonPath('finances.real_spending', 0)
            ->assertJsonPath('finances.balance', 5000);
    }

    public function test_balance_excludes_credit_card_purchase_from_real_spending_and_balance(): void
    {
        Spending::factory()->create([
            'user_id' => $this->user->id,
            'planned_spending' => 2500,
            'created_at' => '2026-03-02 10:00:00',
        ]);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->incomeCategory->id,
            'type_id' => 1,
            'payment_method_id' => 1,
            'transaction_description' => 'Salario',
            'date' => '2026-03-05',
            'transaction_value' => 5000,
        ]);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra no cartao',
            'date' => '2026-03-10',
            'transaction_value' => 900,
        ]);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Mercado',
            'date' => '2026-03-12',
            'transaction_value' => 300,
        ]);

        $this->getJson('/api/balance')
            ->assertOk()
            ->assertJsonPath('finances.planned_spending', 2500)
            ->assertJsonPath('finances.real_spending', 300)
            ->assertJsonPath('finances.balance', 4700);
    }

    public function test_balance_counts_invoice_payment_as_real_spending_and_affects_balance(): void
    {
        Spending::factory()->create([
            'user_id' => $this->user->id,
            'planned_spending' => 2500,
            'created_at' => '2026-03-02 10:00:00',
        ]);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->incomeCategory->id,
            'type_id' => 1,
            'payment_method_id' => 1,
            'transaction_description' => 'Salario',
            'date' => '2026-03-05',
            'transaction_value' => 5000,
        ]);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra no cartao',
            'date' => '2026-03-10',
            'transaction_value' => 850,
        ]);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->invoiceCategory->id,
            'type_id' => 2,
            'payment_method_id' => 2,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-15',
            'transaction_value' => 850,
        ]);

        $this->getJson('/api/balance')
            ->assertOk()
            ->assertJsonPath('finances.planned_spending', 2500)
            ->assertJsonPath('finances.real_spending', 850)
            ->assertJsonPath('finances.balance', 4150);
    }
}
