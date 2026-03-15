<?php

namespace App\Services\Cards;

use App\Http\Controllers\LevelController;
use App\Models\Card;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CardCreationService
{
    /**
     * @throws ValidationException
     */
    public function create(int $userId, array $payload): array
    {
        $validated = Validator::make($payload, [
            'card_description' => ['required', 'string', 'min:2', 'max:50'],
            'flag_id' => ['required', 'exists:flags,id'],
            'expiration' => ['required', 'integer', 'min:1', 'max:31', 'different:closure'],
            'closure' => ['required', 'integer', 'min:1', 'max:31'],
        ], [
            'expiration.different' => 'O vencimento nao pode ser no mesmo dia do fechamento.',
        ])->validate();

        $card = Card::create([
            'user_id' => $userId,
            'flag_id' => (int) $validated['flag_id'],
            'card_description' => trim((string) $validated['card_description']),
            'expiration' => (int) $validated['expiration'],
            'closure' => (int) $validated['closure'],
        ]);

        LevelController::completeMission(3);

        return [
            'status' => 'created',
            'card' => $card,
        ];
    }
}
