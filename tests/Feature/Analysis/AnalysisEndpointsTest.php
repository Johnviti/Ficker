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

class AnalysisEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Card $card;
    protected Category $foodCategory;
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
        Flag::factory()->create(['id' => 1, 'flag_description' => 'Mastercard']);

        $this->user = User::factory()->create(['level_id' => 1]);
        Sanctum::actingAs($this->user);

        $this->foodCategory = Category::factory()->create([
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

        $incomeCategory = Category::factory()->create([
            'id' => 41,
            'user_id' => $this->user->id,
            'type_id' => 1,
            'category_description' => 'Salario',
        ]);

        $this->card = Card::factory()->create([
            'id' => 22,
            'user_id' => $this->user->id,
            'flag_id' => 1,
            'card_description' => 'Cartao Principal2',
            'closure' => 15,
            'expiration' => 3,
        ]);

        Spending::factory()->create([
            'user_id' => $this->user->id,
            'planned_spending' => 2500,
            'created_at' => '2026-03-02 10:00:00',
        ]);

        Transaction::factory()->create([
            'id' => 100,
            'user_id' => $this->user->id,
            'category_id' => $incomeCategory->id,
            'type_id' => 1,
            'payment_method_id' => 1,
            'transaction_description' => 'Salario empresa',
            'date' => '2026-03-05',
            'transaction_value' => 5000,
            'card_id' => null,
        ]);

        Transaction::factory()->create([
            'id' => 101,
            'user_id' => $this->user->id,
            'category_id' => $this->foodCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Mercado bairro',
            'date' => '2026-03-10',
            'transaction_value' => 300,
            'card_id' => null,
        ]);

        Transaction::factory()->create([
            'id' => 102,
            'user_id' => $this->user->id,
            'category_id' => $this->foodCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra cartao mercado',
            'date' => '2026-03-12',
            'transaction_value' => 600,
            'card_id' => $this->card->id,
            'installments' => 2,
        ]);

        Transaction::factory()->create([
            'id' => 103,
            'user_id' => $this->user->id,
            'category_id' => $this->foodCategory->id,
            'type_id' => 2,
            'payment_method_id' => 4,
            'transaction_description' => 'Compra cartao farmacia',
            'date' => '2026-03-13',
            'transaction_value' => 350,
            'card_id' => $this->card->id,
            'installments' => 1,
        ]);

        Transaction::factory()->create([
            'id' => 113,
            'user_id' => $this->user->id,
            'category_id' => $this->invoiceCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Pagamento fatura - Cartao Principal2',
            'date' => '2026-03-15',
            'transaction_value' => 850,
            'card_id' => null,
        ]);

        Installment::create([
            'transaction_id' => 102,
            'installment_description' => 'Compra cartao mercado 1/2',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'paid_at' => '2026-03-15 18:07:19',
            'payment_transaction_id' => 113,
        ]);

        Installment::create([
            'transaction_id' => 102,
            'installment_description' => 'Compra cartao mercado 2/2',
            'installment_value' => 300,
            'card_id' => $this->card->id,
            'pay_day' => '2026-05-03',
            'paid_at' => null,
            'payment_transaction_id' => null,
        ]);

        Installment::create([
            'transaction_id' => 103,
            'installment_description' => 'Compra cartao farmacia 1/1',
            'installment_value' => 350,
            'card_id' => $this->card->id,
            'pay_day' => '2026-04-03',
            'paid_at' => '2026-03-15 18:07:19',
            'payment_transaction_id' => 113,
        ]);

        User::factory()->create(['level_id' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_endpoint_returns_canonical_financial_semantics(): void
    {
        $response = $this->getJson('/api/analysis/summary?month=3&year=2026');

        $response->assertOk()
            ->assertJsonPath('data.income_total', 5000)
            ->assertJsonPath('data.real_spending_total', 1150)
            ->assertJsonPath('data.credit_card_purchase_total', 950)
            ->assertJsonPath('data.invoice_payment_total', 850)
            ->assertJsonPath('data.planned_spending_total', 2500)
            ->assertJsonPath('data.balance_delta', 3850)
            ->assertJsonPath('filters.period_mode', 'month')
            ->assertJsonPath('meta.timezone', 'America/Sao_Paulo');
    }

    public function test_timeline_endpoint_returns_monthly_series(): void
    {
        $response = $this->getJson('/api/analysis/timeline?month=3&year=2026&group_by=month');

        $response->assertOk()
            ->assertJsonPath('data.series.0.period_key', '2026-03')
            ->assertJsonPath('data.series.0.income_total', 5000)
            ->assertJsonPath('data.series.0.real_spending_total', 1150)
            ->assertJsonPath('data.series.0.credit_card_purchase_total', 950)
            ->assertJsonPath('data.series.0.invoice_payment_total', 850);
    }

    public function test_cards_endpoint_returns_current_exposure_and_period_activity(): void
    {
        $response = $this->getJson('/api/analysis/cards?month=3&year=2026');

        $response->assertOk()
            ->assertJsonPath('data.cards.0.card_id', 22)
            ->assertJsonPath('data.cards.0.current_invoice_pay_day', '2026-05-03')
            ->assertJsonPath('data.cards.0.current_invoice_total', 300)
            ->assertJsonPath('data.cards.0.open_invoice_total', 300)
            ->assertJsonPath('data.cards.0.future_commitment_total', 0)
            ->assertJsonPath('data.cards.0.purchases_total_in_period', 950)
            ->assertJsonPath('data.cards.0.invoice_payments_total_in_period', 650)
            ->assertJsonPath('data.cards.0.paid_invoices_count_in_period', 1);
    }

    public function test_invoices_endpoint_returns_invoice_competence_data(): void
    {
        $response = $this->getJson('/api/analysis/invoices?date_from=2026-04-01&date_to=2026-05-31&card_id=22');

        $response->assertOk()
            ->assertJsonCount(2, 'data.invoices')
            ->assertJsonPath('data.invoices.0.pay_day', '2026-04-03')
            ->assertJsonPath('data.invoices.0.invoice_total', 650)
            ->assertJsonPath('data.invoices.0.open_total', 0)
            ->assertJsonPath('data.invoices.0.is_paid', true)
            ->assertJsonPath('data.invoices.0.payment_transaction_id', 113)
            ->assertJsonPath('data.invoices.1.pay_day', '2026-05-03')
            ->assertJsonPath('data.invoices.1.invoice_total', 300)
            ->assertJsonPath('data.invoices.1.open_total', 300)
            ->assertJsonPath('data.invoices.1.is_paid', false);
    }

    public function test_categories_endpoint_separates_real_spending_from_credit_card_purchases(): void
    {
        $response = $this->getJson('/api/analysis/categories?month=3&year=2026');

        $response->assertOk()
            ->assertJsonPath('data.categories.0.category_description', 'Alimentacao')
            ->assertJsonPath('data.categories.0.real_spending_total', 300)
            ->assertJsonPath('data.categories.0.credit_card_purchase_total', 950)
            ->assertJsonPath('data.categories.0.invoice_payment_total', 0)
            ->assertJsonPath('data.categories.1.category_description', 'Pagamento de fatura')
            ->assertJsonPath('data.categories.1.real_spending_total', 850)
            ->assertJsonPath('data.categories.1.invoice_payment_total', 850);
    }

    public function test_composition_endpoint_returns_aggregated_percentages(): void
    {
        $response = $this->getJson('/api/analysis/composition?month=3&year=2026');

        $response->assertOk()
            ->assertJsonPath('data.income_total', 5000)
            ->assertJsonPath('data.real_spending_total', 1150)
            ->assertJsonPath('data.credit_card_purchase_total', 950)
            ->assertJsonPath('data.financial_outflow_total', 2100)
            ->assertJsonPath('data.composition_percentages.invoice_payments_within_real_spending', 73.91);
    }

    public function test_top_expenses_endpoint_returns_rankings_and_honors_limit(): void
    {
        $response = $this->getJson('/api/analysis/top-expenses?month=3&year=2026&limit=1');

        $response->assertOk()
            ->assertJsonPath('data.limit', 1)
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonCount(1, 'data.cards')
            ->assertJsonPath('data.transactions.0.transaction_id', 113)
            ->assertJsonPath('data.transactions.0.is_invoice_payment', true);
    }

    public function test_analysis_endpoints_validate_filters(): void
    {
        $response = $this->getJson('/api/analysis/summary?date_from=2026-03-31&date_to=2026-03-01');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Os filtros informados sao invalidos.')
            ->assertJsonStructure([
                'message',
                'errors' => ['date_to'],
            ]);
    }

    public function test_analysis_endpoints_reject_unknown_card_filter(): void
    {
        $response = $this->getJson('/api/analysis/cards?card_id=999');

        $response->assertStatus(422)
            ->assertJsonPath('errors.card_id.0', 'Cartao nao encontrado.');
    }
}
