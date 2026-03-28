<?php

namespace Database\Seeders;

use App\Models\Flag;
use App\Models\Level;
use App\Models\Mission;
use App\Models\PaymentMethod;
use App\Models\Type;
use Illuminate\Database\Seeder;

class BaseCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedLevels();
        $this->seedMissions();
        $this->seedFlags();
        $this->seedTypes();
        $this->seedPaymentMethods();
    }

    private function seedLevels(): void
    {
        $levels = [
            ['id' => 1, 'level_description' => 'Padawan', 'level_xp' => 0],
            ['id' => 2, 'level_description' => 'Ficker Knight', 'level_xp' => 125],
            ['id' => 3, 'level_description' => 'Ficker Master', 'level_xp' => 250],
            ['id' => 4, 'level_description' => 'Ficker Grand Master', 'level_xp' => 500],
        ];

        foreach ($levels as $level) {
            Level::updateOrCreate(['id' => $level['id']], $level);
        }
    }

    private function seedMissions(): void
    {
        $missions = [
            ['id' => 1, 'mission_description' => 'Adicionar transacao de entrada', 'mission_xp' => 25],
            ['id' => 2, 'mission_description' => 'Adicionar transacao de saida', 'mission_xp' => 25],
            ['id' => 3, 'mission_description' => 'Adicionar cartao de credito', 'mission_xp' => 25],
            ['id' => 4, 'mission_description' => 'Adicionar transacao de cartao de credito', 'mission_xp' => 25],
            ['id' => 5, 'mission_description' => 'Criar nova categoria', 'mission_xp' => 25],
            ['id' => 6, 'mission_description' => 'Finalizar um mes com orcamento dentro do gasto planejado', 'mission_xp' => 100],
        ];

        foreach ($missions as $mission) {
            Mission::updateOrCreate(['id' => $mission['id']], $mission);
        }
    }

    private function seedFlags(): void
    {
        $flags = [
            ['id' => 1, 'flag_description' => 'Mastercard'],
            ['id' => 2, 'flag_description' => 'Visa'],
            ['id' => 3, 'flag_description' => 'Hipercard'],
            ['id' => 4, 'flag_description' => 'Elo'],
            ['id' => 5, 'flag_description' => 'Alelo'],
            ['id' => 6, 'flag_description' => 'American Express'],
            ['id' => 7, 'flag_description' => 'Diners Club'],
        ];

        foreach ($flags as $flag) {
            Flag::updateOrCreate(['id' => $flag['id']], $flag);
        }
    }

    private function seedTypes(): void
    {
        $types = [
            ['id' => 1, 'type_description' => 'Entrada'],
            ['id' => 2, 'type_description' => 'Saida'],
        ];

        foreach ($types as $type) {
            Type::updateOrCreate(['id' => $type['id']], $type);
        }
    }

    private function seedPaymentMethods(): void
    {
        $paymentMethods = [
            ['id' => 1, 'payment_method_description' => 'Dinheiro'],
            ['id' => 2, 'payment_method_description' => 'Pix'],
            ['id' => 3, 'payment_method_description' => 'Cartao de debito'],
            ['id' => 4, 'payment_method_description' => 'Cartao de credito'],
        ];

        foreach ($paymentMethods as $paymentMethod) {
            PaymentMethod::updateOrCreate(['id' => $paymentMethod['id']], $paymentMethod);
        }
    }
}
