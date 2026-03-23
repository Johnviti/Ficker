<?php

namespace Tests\Feature\Card;

use App\Models\Card;
use App\Models\Category;
use App\Models\Flag;
use App\Models\Level;
use App\Models\PaymentMethod;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CardScopeEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Card $foreignCard;

    protected function setUp(): void
    {
        parent::setUp();

        Level::factory()->create(['id' => 1]);
        Type::factory()->create(['id' => 1, 'type_description' => 'Entrada']);
        Type::factory()->create(['id' => 2, 'type_description' => 'Saida']);
        PaymentMethod::factory()->create(['id' => 1, 'payment_method_description' => 'Dinheiro']);
        PaymentMethod::factory()->create(['id' => 2, 'payment_method_description' => 'Pix']);
        PaymentMethod::factory()->create(['id' => 4, 'payment_method_description' => 'Cartao de credito']);
        Flag::factory()->create(['id' => 1, 'flag_description' => 'Mastercard']);

        $this->user = User::factory()->create(['level_id' => 1]);
        Sanctum::actingAs($this->user);

        $otherUser = User::factory()->create(['level_id' => 1]);

        Category::factory()->create([
            'user_id' => $otherUser->id,
            'type_id' => 2,
            'category_description' => 'Outra categoria',
        ]);

        $this->foreignCard = Card::factory()->create([
            'user_id' => $otherUser->id,
            'flag_id' => 1,
            'card_description' => 'Cartao Alheio',
            'closure' => 15,
            'expiration' => 3,
        ]);
    }

    public function test_show_card_invoice_returns_not_found_for_card_outside_user_scope(): void
    {
        $this->getJson("/api/cards/{$this->foreignCard->id}/invoice")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Cartao nao encontrado.');
    }

    public function test_show_invoice_installments_returns_not_found_for_card_outside_user_scope(): void
    {
        $this->getJson("/api/cards/{$this->foreignCard->id}/installments")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Cartao nao encontrado.');
    }

    public function test_show_invoices_returns_not_found_for_card_outside_user_scope(): void
    {
        $this->getJson("/api/cards/{$this->foreignCard->id}/invoices")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Cartao nao encontrado ou sem faturas.');
    }

    public function test_pay_invoice_by_pay_day_returns_not_found_for_card_outside_user_scope(): void
    {
        $this->postJson("/api/cards/{$this->foreignCard->id}/invoices/2026-04-03/pay", [
            'payment_method_id' => 1,
        ])->assertStatus(404)
            ->assertJsonPath('message', 'Cartao nao encontrado.');
    }

    public function test_pay_next_invoice_returns_not_found_for_card_outside_user_scope(): void
    {
        $this->postJson("/api/cards/{$this->foreignCard->id}/pay-invoice", [
            'payment_method_id' => 1,
        ])->assertStatus(404)
            ->assertJsonPath('message', 'Cartao nao encontrado.');
    }
}
