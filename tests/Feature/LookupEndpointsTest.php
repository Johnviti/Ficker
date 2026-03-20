<?php

namespace Tests\Feature;

use App\Models\Flag;
use App\Models\Level;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LookupEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Level::factory()->create(['id' => 1]);
        Sanctum::actingAs(User::factory()->create(['level_id' => 1]));
    }

    public function test_show_payment_methods_returns_formatted_payment_methods(): void
    {
        PaymentMethod::factory()->create([
            'id' => 1,
            'payment_method_description' => 'Dinheiro',
        ]);

        PaymentMethod::factory()->create([
            'id' => 2,
            'payment_method_description' => 'Pix',
        ]);

        $this->getJson('/api/payment/methods')
            ->assertOk()
            ->assertJsonCount(2, 'data.payment_methods')
            ->assertJsonPath('data.payment_methods.0.id', 1)
            ->assertJsonPath('data.payment_methods.0.description', 'Dinheiro')
            ->assertJsonPath('data.payment_methods.1.id', 2)
            ->assertJsonPath('data.payment_methods.1.description', 'Pix');
    }

    public function test_show_flags_returns_all_flags(): void
    {
        Flag::factory()->create([
            'id' => 1,
            'flag_description' => 'Mastercard',
        ]);

        Flag::factory()->create([
            'id' => 2,
            'flag_description' => 'Visa',
        ]);

        $this->getJson('/api/flags')
            ->assertOk()
            ->assertJsonCount(2, 'data.flags')
            ->assertJsonPath('data.flags.0.id', 1)
            ->assertJsonPath('data.flags.0.flag_description', 'Mastercard')
            ->assertJsonPath('data.flags.1.id', 2)
            ->assertJsonPath('data.flags.1.flag_description', 'Visa');
    }
}
