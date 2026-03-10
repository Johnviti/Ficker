<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Card;
use App\Models\Flag;
use App\Models\Installment;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\Category;
use Illuminate\Validation\ValidationException;

class CardController extends Controller
{
    private function nextInvoicePayDay(Card $card): ?string
    {
        $today = Carbon::today()->toDateString();

        // Retorna a fatura em aberto mais antiga
        return Installment::where('card_id', $card->id)
            ->whereNull('paid_at')
            ->orderBy('pay_day', 'asc')
            ->value('pay_day');
    }

    private function invoiceClosureDate(Card $card, string $invoicePayDay): \Carbon\Carbon
    {
        $payDay = \Carbon\Carbon::parse($invoicePayDay)->startOfDay();

        $closureDay = (int) $card->closure;
        $expirationDay = (int) $card->expiration;

        // Se vencimento > fechamento: fechamento acontece no MESMO mês do vencimento.
        // Ex: fechamento 10, vencimento 20, fatura vence 20/03 e fecha 10/03.
        if ($expirationDay > $closureDay) {
            $closureMonth = $payDay->copy(); // mesmo mês do payDay
        } else {
            // Se vencimento < fechamento: fechamento acontece no mês ANTERIOR ao vencimento.
            // Ex: fechamento 15, vencimento 03, fatura vence 03/04 e fecha 15/03.
            $closureMonth = $payDay->copy()->subMonth();
        }

        $closureDate = \Carbon\Carbon::create(
            $closureMonth->year,
            $closureMonth->month,
            min($closureDay, $closureMonth->daysInMonth),
            0, 0, 0
        );

        return $closureDate;
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
                ->whereNull('paid_at')
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
                    ->whereNull('paid_at')
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
                    ->whereNull('paid_at')
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


    public function showInvoices($id): JsonResponse
    {
        try {
            $card = Card::where('user_id', Auth::id())->findOrFail($id);

            $installments = Installment::where('card_id', $card->id)
                ->orderBy('pay_day', 'asc')
                ->get();

            // agrupa por pay_day (normaliza para string)
            $grouped = $installments->groupBy(function ($inst) {
                // garante formato consistente
                return \Carbon\Carbon::parse($inst->pay_day)->toDateString();
            });

            $invoices = [];

            foreach ($grouped as $payDay => $items) {
                $total = (float) $items->sum('installment_value');
                $openTotal = (float) $items->whereNull('paid_at')->sum('installment_value');

                $isPaid = $openTotal <= 0;

                // data do pagamento (se paga): pega o maior paid_at dentro do grupo
                $lastPaidAt = $items->max('paid_at');
                $lastPaidAt = $lastPaidAt ? \Carbon\Carbon::parse($lastPaidAt)->toDateTimeString() : null;

                $closureDate = $this->invoiceClosureDate($card, $payDay)->toDateString();

                $invoices[] = [
                    'pay_day' => $payDay,
                    'closure_date' => $closureDate,
                    'total' => $total,
                    'open_total' => $openTotal,
                    'is_paid' => $isPaid,
                    'installments_count' => $items->count(),
                    'paid_at' => $lastPaidAt,
                ];
            }

            return response()->json([
                'data' => [
                    'card_id' => $card->id,
                    'invoices' => $invoices
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'data' => [
                    'message' => 'Cartão não encontrado ou sem faturas.',
                    'error' => $e->getMessage()
                ]
            ], 404);
        }
    }

    private function payInvoiceCore(Request $request, Card $card, string $invoicePayDay): JsonResponse
    {
        $request->validate([
            'payment_method_id' => ['required', 'integer', 'not_in:4'],
            'category_id' => ['nullable', 'integer', 'min:0'],
            'category_description' => ['required_if:category_id,0', 'string', 'max:50'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'payment_method_id.not_in' => 'Pagamento de fatura não pode ser feito com método cartão de crédito.',
        ]);

        $invoicePayDay = \Carbon\Carbon::parse($invoicePayDay)->toDateString();
        $userId = Auth::id();

        // 1) parcelas em aberto
        $installmentsQuery = Installment::where('card_id', $card->id)
            ->whereNull('paid_at')
            ->whereDate('pay_day', $invoicePayDay);

        $openTotal = (float) $installmentsQuery->sum('installment_value');

        if ($openTotal <= 0) {
            return response()->json([
                'data' => [
                    'message' => 'Esta fatura não possui parcelas em aberto (já paga ou inexistente).',
                    'invoice_pay_day' => $invoicePayDay,
                ]
            ], 422);
        }

        // 2) bloqueio após fechamento (Modelo A)
        $closureDate = $this->invoiceClosureDate($card, $invoicePayDay);
        $today = \Carbon\Carbon::today()->startOfDay();

        if ($today->lt($closureDate)) {
            return response()->json([
                'data' => [
                    'message' => 'A fatura ainda não fechou. Você só pode pagar após o fechamento.',
                    'invoice_pay_day' => $invoicePayDay,
                    'invoice_closure_date' => $closureDate->toDateString(),
                ]
            ], 422);
        }

        // 3) categoria (mesma lógica atual)
        $paymentDate = $request->date ?? \Carbon\Carbon::today()->toDateString();
        $categoryId = $request->category_id;

        if (is_null($categoryId)) {
            $category = Category::where('user_id', $userId)
                ->where('type_id', 2)
                ->where('category_description', 'Pagamento de fatura')
                ->first();

            if (!$category) {
                $category = Category::create([
                    'user_id' => $userId,
                    'type_id' => 2,
                    'category_description' => 'Pagamento de fatura'
                ]);
            }

            $categoryId = $category->id;

        } elseif ((int) $categoryId === 0) {
            $category = Category::create([
                'type_id' => 2,
                'category_description' => $request->category_description
            ]);
            $categoryId = $category->id;

        } else {
            $category = Category::find($categoryId);

            if (!$category) {
                return response()->json([
                    'message' => 'Categoria não encontrada.',
                    'errors' => [
                        'category_id' => ['Categoria não encontrada.']
                    ]
                ], 404);
            }

            if ((int) $category->type_id !== 2) {
                return response()->json([
                    'message' => 'A categoria selecionada não corresponde ao tipo da transação.',
                    'errors' => [
                        'category_id' => ['A categoria selecionada não corresponde ao tipo da transação.']
                    ]
                ], 422);
            }
        }

        // 4) cria transação + baixa parcelas
        $paymentTransaction = DB::transaction(function () use (
            $userId,
            $card,
            $categoryId,
            $openTotal,
            $paymentDate,
            $request,
            $invoicePayDay
        ) {
            $paymentTransaction = Transaction::create([
                'user_id' => $userId,
                'category_id' => $categoryId,
                'type_id' => 2,
                'payment_method_id' => (int) $request->payment_method_id,
                'transaction_description' => 'Pagamento fatura - ' . $card->card_description,
                'date' => $paymentDate,
                'transaction_value' => $openTotal,
            ]);

            Installment::where('card_id', $card->id)
                ->whereNull('paid_at')
                ->whereDate('pay_day', $invoicePayDay)
                ->update([
                    'paid_at' => now(),
                    'payment_transaction_id' => $paymentTransaction->id
                ]);

            return $paymentTransaction;
        });

        return response()->json([
            'data' => [
                'message' => 'Fatura paga com sucesso.',
                'card_id' => $card->id,
                'pay_day' => $invoicePayDay,
                'invoice_value' => $openTotal,
                'payment_transaction' => $paymentTransaction
            ]
        ], 200);
    }

    public function payInvoiceByPayDay(Request $request, $id, $pay_day): JsonResponse
    {
        try {
            $card = Card::where('user_id', Auth::id())->findOrFail($id);
            return $this->payInvoiceCore($request, $card, $pay_day);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->validator->errors()->first(),
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'data' => [
                    'message' => 'Erro ao pagar fatura.',
                    'error' => $e->getMessage()
                ]
            ], 500);
        }
    }

    public function payNextInvoice(Request $request, $id): JsonResponse
    {
        try {
            $card = Card::where('user_id', Auth::id())->findOrFail($id);

            $nextPayDay = $this->nextInvoicePayDay($card);

            if (!$nextPayDay) {
                return response()->json([
                    'data' => [
                        'message' => 'Não há fatura em aberto para este cartão.'
                    ]
                ], 422);
            }

            return $this->payInvoiceCore($request, $card, $nextPayDay);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->validator->errors()->first(),
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'data' => [
                    'message' => 'Erro ao pagar fatura.',
                    'error' => $e->getMessage()
                ]
            ], 500);
        }
    }

}
