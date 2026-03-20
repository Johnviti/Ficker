<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Level;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\Type;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00', 'America/Sao_Paulo'));

        Level::factory()->create(['id' => 1]);
        Type::factory()->create(['id' => 1, 'type_description' => 'Entrada']);
        Type::factory()->create(['id' => 2, 'type_description' => 'Saida']);
        Type::factory()->create(['id' => 3, 'type_description' => 'Ambos']);
        PaymentMethod::factory()->create(['id' => 1, 'payment_method_description' => 'Dinheiro']);

        $this->user = User::factory()->create(['level_id' => 1]);
        $this->otherUser = User::factory()->create(['level_id' => 1]);

        Sanctum::actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_store_category_creates_category_for_authenticated_user(): void
    {
        $this->postJson('/api/category/store', [
            'category_description' => 'Lazer',
            'type_id' => 2,
        ])->assertStatus(201)
            ->assertJsonPath('data.category.user_id', $this->user->id)
            ->assertJsonPath('data.category.category_description', 'Lazer')
            ->assertJsonPath('data.category.type_id', 2);

        $this->assertDatabaseHas('categories', [
            'user_id' => $this->user->id,
            'category_description' => 'Lazer',
            'type_id' => 2,
        ]);
    }

    public function test_show_categories_returns_only_authenticated_user_categories_with_month_spending(): void
    {
        $foodCategory = Category::factory()->create([
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Alimentacao',
        ]);

        Category::factory()->create([
            'user_id' => $this->otherUser->id,
            'type_id' => 2,
            'category_description' => 'Categoria outra conta',
        ]);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $foodCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Mercado',
            'date' => '2026-03-12',
            'transaction_value' => 300,
        ]);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $foodCategory->id,
            'type_id' => 2,
            'payment_method_id' => 1,
            'transaction_description' => 'Mercado mes anterior',
            'date' => '2026-02-10',
            'transaction_value' => 500,
        ]);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.categories.0.id', $foodCategory->id)
            ->assertJsonPath('data.categories.0.category_description', 'Alimentacao')
            ->assertJsonPath('data.categories.0.category_spending', 300);
    }

    public function test_show_categories_by_type_returns_only_authenticated_user_categories_of_requested_type(): void
    {
        Category::factory()->create([
            'user_id' => $this->user->id,
            'type_id' => 1,
            'category_description' => 'Salario',
        ]);

        Category::factory()->create([
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Alimentacao',
        ]);

        Category::factory()->create([
            'user_id' => $this->otherUser->id,
            'type_id' => 2,
            'category_description' => 'Outra conta',
        ]);

        $response = $this->getJson('/api/categories/type/2');

        $response->assertOk()
            ->assertJsonPath('0.category_description', 'Alimentacao')
            ->assertJsonPath('0.user_id', $this->user->id)
            ->assertJsonPath('0.type_id', 2);

        $this->assertCount(1, $response->json());
    }

    public function test_show_categories_by_type_returns_validation_error_for_invalid_type(): void
    {
        $this->getJson('/api/categories/type/9')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tipo de categoria invalido.');
    }

    public function test_show_category_returns_only_category_in_user_scope(): void
    {
        $category = Category::factory()->create([
            'user_id' => $this->user->id,
            'type_id' => 2,
            'category_description' => 'Transporte',
        ]);

        Category::factory()->create([
            'user_id' => $this->otherUser->id,
            'type_id' => 2,
            'category_description' => 'Privada',
        ]);

        $this->getJson("/api/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.category_description', 'Transporte');
    }

    public function test_show_category_returns_not_found_for_category_outside_user_scope(): void
    {
        $otherCategory = Category::factory()->create([
            'user_id' => $this->otherUser->id,
            'type_id' => 2,
            'category_description' => 'Privada',
        ]);

        $this->getJson("/api/categories/{$otherCategory->id}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Categoria nao encontrada.');
    }
}
