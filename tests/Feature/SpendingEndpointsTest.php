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

class SpendingEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Category $incomeCategory;
    protected Category $expenseCategory;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00', 'America/Sao_Paulo'));

        Level::factory()->create(['id' => 1]);
        Type::factory()->create(['id' => 1, 'type_description' => 'Entrada']);
        Type::factory()->create(['id' => 2, 'type_description' => 'Saida']);
        PaymentMethod::factory()->create(['id' => 1, 'payment_method_description' => 'Dinheiro']);
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_show_spending_returns_null_when_user_has_no_planned_spending(): void
    {
        $this->getJson('/api/spending')
            ->assertOk()
            ->assertJsonPath('data.spending', null);
    }

    public function test_store_spending_creates_planned_spending_record(): void
    {
        $this->postJson('/api/spending/store', [
            'planned_spending' => 2500,
        ])->assertStatus(201)
            ->assertJsonPath('spending.user_id', $this->user->id)
            ->assertJsonPath('spending.planned_spending', 2500);

        $this->assertDatabaseHas('spendings', [
            'user_id' => $this->user->id,
            'planned_spending' => 2500,
        ]);
    }

    public function test_update_spending_updates_existing_record(): void
    {
        $spending = Spending::factory()->create([
            'user_id' => $this->user->id,
            'planned_spending' => 2000,
        ]);

        $this->putJson('/api/spending/update/' . $spending->id, [
            'id' => $spending->id,
            'planned_spending' => 2800,
        ])->assertOk()
            ->assertJsonPath('data.spending.id', $spending->id)
            ->assertJsonPath('data.spending.planned_spending', 2800);

        $this->assertDatabaseHas('spendings', [
            'id' => $spending->id,
            'planned_spending' => 2800,
        ]);
    }

    public function test_spendings_by_month_returns_incomes_real_spending_and_planned_spending(): void
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
            'payment_method_id' => 1,
            'transaction_description' => 'Mercado',
            'date' => '2026-03-10',
            'transaction_value' => 300,
        ]);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra cartao',
            'date' => '2026-03-11',
            'transaction_value' => 800,
        ]);

        $response = $this->getJson('/api/spendings?sort=month');

        $response->assertOk()
            ->assertJsonPath('data.0.0.month', 3)
            ->assertJsonPath('data.0.0.year', 2026)
            ->assertJsonPath('data.0.0.incomes', 5000)
            ->assertJsonPath('data.0.0.real_spending', 300)
            ->assertJsonPath('data.0.0.planned_spending', 2500);
    }

    public function test_spendings_returns_validation_error_for_invalid_sort_parameter(): void
    {
        $this->getJson('/api/spendings?sort=invalid')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Parametro sort invalido. Use day, month ou year.');
    }
}
