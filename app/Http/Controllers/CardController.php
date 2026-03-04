<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Card;
use App\Models\Flag;
use App\Models\Installment;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class CardController extends Controller
{
    private function nextInvoicePayDay(Card $card): ?string
    {
        $today = Carbon::today()->toDateString();

        // Próximo pay_day >= hoje
        return Installment::where('card_id', $card->id)
            ->whereDate('pay_day', '>=', $today)
            ->orderBy('pay_day', 'asc')
            ->value('pay_day'); // retorna a primeira data
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'card_description' => ['required', 'string', 'min:2', 'max:50'],
            'flag_id' => ['required', 'exists:flags,id'],
            'expiration' => ['required', 'integer', 'min:1', 'max:31', 'different:closure'],
            'closure' => ['required', 'integer', 'min:1', 'max:31'],
        ], [ 
             'expiration.different' => 'O vencimento não pode ser no mesmo dia do fechamento.',
        ]);


        $card = Card::create([
            'user_id' => Auth::user()->id,
            'flag_id' => $request->flag_id,
            'card_description' => $request->card_description,
            'expiration' => $request->expiration,
            'closure' => $request->closure
        ]);

        LevelController::completeMission(3);

        $response = [
            'card' => $card
        ];

        return response()->json($response, 201);
    }

    public function showCards(): JsonResponse
    {
        try {
            $cards = Auth::user()->cards;
            $response = ['data' => ['cards' => []]];
            foreach ($cards as $card) {
                $invoice = Self::invoice($card->id);
                $card->invoice = $invoice;
                array_push($response['data']['cards'], $card);
            }
            return response()->json($response, 200);
            
        } catch (\Exception $e) {
            $errorMessage = "Nenhum cartão cadastrado";
            $response = [
                "data" => [
                    "error" => $errorMessage
                ]
            ];
            return response()->json($response, 404);
        }
    }

    public function showFlags(): JsonResponse
    {
        try {
            $flags = Flag::all();
            $response = ['data' => ['flags' => []]];
            foreach ($flags as $flag) {
                array_push($response['data']['flags'], $flag);
            }
            return response()->json($response, 200);
        } catch (\Exception $e) {
            $errorMessage = "Nenhuma bandeira foi encontrada";
            $response = [
                "data" => [
                    "error" => $errorMessage
                ]
            ];

            return response()->json($response, 404);
        }
    }

    public function invoice($id)
    {
        try {
            $card = Card::where('user_id', Auth::id())->findOrFail($id);

            $nextPayDay = $this->nextInvoicePayDay($card);

            if (!$nextPayDay) {
                return 0;
            }

            return (float) Installment::where('card_id', $card->id)
                ->whereDate('pay_day', $nextPayDay)
                ->sum('installment_value');

        } catch (\Exception $e) {
            return 0;
        }
    }

    public function showCardInvoice($id): JsonResponse
    {
        try {
            $card = Card::where('user_id', Auth::id())->findOrFail($id);

            $nextPayDay = $this->nextInvoicePayDay($card);

            $invoice = 0;

            if ($nextPayDay) {
                $invoice = Installment::where('card_id', $card->id)
                    ->whereDate('pay_day', $nextPayDay)
                    ->sum('installment_value');
            }

            return response()->json([
                "data" => [
                    "invoice" => $invoice,
                    "pay_day" => $nextPayDay
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "data" => [
                    "error" => $e->getMessage()
                ]
            ], 404);
        }
    }

    public function showInvoiceInstallments($id): JsonResponse
    {
        try {
            $card = Card::where('user_id', Auth::id())->findOrFail($id);

            $nextPayDay = $this->nextInvoicePayDay($card);

            $installments = [];

            if ($nextPayDay) {
                $installments = Installment::where('card_id', $card->id)
                    ->whereDate('pay_day', $nextPayDay)
                    ->get();
            }

            return response()->json([
                "data" => [
                    "pay_day" => $nextPayDay,
                    "installments" => $installments
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "data" => [
                    "error" => $e->getMessage()
                ]
            ], 404);
        }
    }
}
