<?php

namespace Tests\Feature\Analysis;

use App\Models\Card;
use App\Models\Category;
use App\Models\Flag;
use App\Models\Installment;
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

class AnalysisSupplementalEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Card $cardA;
    protected Card $cardB;
    protected Category $foodCategory;
    protected Category $transportCategory;
    protected Category $incomeCategory;

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

        $this->incomeCategory = Category::factory()->create([
            'id' => 41,
            'user_id' => $this->user->id,
            'type_id' => 1,
            'category_description' => 'Salario',
        ]);

        $this->foodCategory = Category::factory()->create([
            'id' => 40,
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Alimentacao',
        ]);

        $this->transportCategory = Category::factory()->create([
            'id' => 43,
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Transporte',
        ]);

        $this->cardA = Card::factory()->create([
            'id' => 22,
            'user_id' => $this->user->id,
            'flag_id' => 1,
            'card_description' => 'Cartao Principal2',
            'closure' => 15,
            'expiration' => 3,
        ]);

        $this->cardB = Card::factory()->create([
            'id' => 23,
            'user_id' => $this->user->id,
            'flag_id' => 1,
            'card_description' => 'Cartao Reserva',
            'closure' => 8,
            'expiration' => 12,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_endpoint_returns_zeroes_for_empty_period(): void
    {
        $response = $this->getJson('/api/analysis/summary?month=3&year=2026');

        $response->assertOk()
            ->assertJsonPath('data.income_total', 0)
            ->assertJsonPath('data.real_spending_total', 0)
            ->assertJsonPath('data.credit_card_purchase_total', 0)
            ->assertJsonPath('data.invoice_payment_total', 0)
            ->assertJsonPath('data.planned_spending_total', 0)
            ->assertJsonPath('data.balance_delta', 0)
            ->assertJsonPath('data.total_transactions_count', 0);
    }

    public function test_timeline_endpoint_returns_daily_series_when_grouped_by_day(): void
    {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->incomeCategory->id,
            'type_id' => 1,
            'payment_method_id' => 1,
            'transaction_description' => 'Salario empresa',
            'date' => '2026-03-05',
            'transaction_value' => 5000,
        ]);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->foodCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Mercado bairro',
            'date' => '2026-03-10',
            'transaction_value' => 300,
        ]);

        $response = $this->getJson('/api/analysis/timeline?date_from=2026-03-01&date_to=2026-03-31&group_by=day');

        $response->assertOk()
            ->assertJsonCount(2, 'data.series')
            ->assertJsonPath('data.series.0.period_key', '2026-03-05')
            ->assertJsonPath('data.series.0.income_total', 5000)
            ->assertJsonPath('data.series.0.real_spending_total', 0)
            ->assertJsonPath('data.series.1.period_key', '2026-03-10')
            ->assertJsonPath('data.series.1.income_total', 0)
            ->assertJsonPath('data.series.1.real_spending_total', 300)
            ->assertJsonPath('data.series.1.planned_spending_total', null);
    }

    public function test_analysis_endpoints_apply_combined_card_category_and_type_filters(): void
    {
        Spending::factory()->create([
            'user_id' => $this->user->id,
            'planned_spending' => 2500,
            'created_at' => '2026-03-02 10:00:00',
        ]);

        Transaction::factory()->create([
            'id' => 100,
            'user_id' => $this->user->id,
            'category_id' => $this->foodCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra cartao alimentacao A',
            'date' => '2026-03-12',
            'transaction_value' => 600,
            'card_id' => $this->cardA->id,
        ]);

        Transaction::factory()->create([
            'id' => 101,
            'user_id' => $this->user->id,
            'category_id' => $this->foodCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra cartao alimentacao B',
            'date' => '2026-03-13',
            'transaction_value' => 200,
            'card_id' => $this->cardB->id,
        ]);

        Transaction::factory()->create([
            'id' => 102,
            'user_id' => $this->user->id,
            'category_id' => $this->transportCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra cartao transporte',
            'date' => '2026-03-14',
            'transaction_value' => 150,
            'card_id' => $this->cardA->id,
        ]);

        $summaryResponse = $this->getJson('/api/analysis/summary?month=3&year=2026&card_id=22&category_id=40&type_id=2');

        $summaryResponse->assertOk()
            ->assertJsonPath('data.credit_card_purchase_total', 600)
            ->assertJsonPath('data.real_spending_total', 0)
            ->assertJsonPath('data.total_transactions_count', 1);

        $categoriesResponse = $this->getJson('/api/analysis/categories?month=3&year=2026&card_id=22&category_id=40&type_id=2');

        $categoriesResponse->assertOk()
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.categories.0.category_id', $this->foodCategory->id)
            ->assertJsonPath('data.categories.0.credit_card_purchase_total', 600);
    }

    public function test_cards_and_invoices_endpoints_handle_multiple_cards_and_invoices(): void
    {
        $invoicePayment = Transaction::factory()->create([
            'id' => 500,
            'user_id' => $this->user->id,
            'category_id' => $this->transportCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-15',
            'transaction_value' => 300,
        ]);

        $purchaseA = Transaction::factory()->create([
            'id' => 110,
            'user_id' => $this->user->id,
            'category_id' => $this->foodCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra A',
            'date' => '2026-03-10',
            'transaction_value' => 600,
            'card_id' => $this->cardA->id,
            'installments' => 2,
        ]);

        $purchaseB = Transaction::factory()->create([
            'id' => 111,
            'user_id' => $this->user->id,
            'category_id' => $this->transportCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra B',
            'date' => '2026-03-11',
            'transaction_value' => 400,
            'card_id' => $this->cardB->id,
            'installments' => 1,
        ]);

        Installment::create([
            'transaction_id' => $purchaseA->id,
            'installment_description' => 'Compra A 1/2',
            'installment_value' => 300,
            'card_id' => $this->cardA->id,
            'pay_day' => '2026-04-03',
            'paid_at' => '2026-03-15 18:07:19',
            'payment_transaction_id' => $invoicePayment->id,
        ]);

        Installment::create([
            'transaction_id' => $purchaseA->id,
            'installment_description' => 'Compra A 2/2',
            'installment_value' => 300,
            'card_id' => $this->cardA->id,
            'pay_day' => '2026-05-03',
            'paid_at' => null,
            'payment_transaction_id' => null,
        ]);

        Installment::create([
            'transaction_id' => $purchaseB->id,
            'installment_description' => 'Compra B 1/1',
            'installment_value' => 400,
            'card_id' => $this->cardB->id,
            'pay_day' => '2026-04-12',
            'paid_at' => null,
            'payment_transaction_id' => null,
        ]);

        $cardsResponse = $this->getJson('/api/analysis/cards?date_from=2026-03-01&date_to=2026-05-31');

        $cardsResponse->assertOk()
            ->assertJsonCount(2, 'data.cards')
            ->assertJsonPath('data.cards.0.card_id', 22)
            ->assertJsonPath('data.cards.0.current_invoice_pay_day', '2026-05-03')
            ->assertJsonPath('data.cards.1.card_id', 23)
            ->assertJsonPath('data.cards.1.current_invoice_pay_day', '2026-04-12');

        $invoicesResponse = $this->getJson('/api/analysis/invoices?date_from=2026-04-01&date_to=2026-05-31');

        $invoicesResponse->assertOk()
            ->assertJsonCount(3, 'data.invoices');
    }
}
