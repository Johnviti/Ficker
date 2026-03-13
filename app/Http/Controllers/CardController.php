<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Category;
use App\Models\Flag;
use App\Models\Installment;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CardController extends Controller
{
    private function nextInvoicePayDay(Card $card): ?string
    {
        return Installment::where('card_id', $card->id)
            ->whereNull('paid_at')
            ->orderBy('pay_day', 'asc')
            ->value('pay_day');
    }

    private function invoiceClosureDate(Card $card, string $invoicePayDay): Carbon
    {
        $payDay = Carbon::parse($invoicePayDay)->startOfDay();

        $closureDay = (int) $card->closure;
        $expirationDay = (int) $card->expiration;

        $closureMonth = $expirationDay > $closureDay
            ? $payDay->copy()
            : $payDay->copy()->subMonth();

        return Carbon::create(
            $closureMonth->year,
            $closureMonth->month,
            min($closureDay, $closureMonth->daysInMonth),
            0,
            0,
            0
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'card_description' => ['required', 'string', 'min:2', 'max:50'],
            'flag_id' => ['required', 'exists:flags,id'],
            'expiration' => ['required', 'integer', 'min:1', 'max:31', 'different:closure'],
            'closure' => ['required', 'integer', 'min:1', 'max:31'],
        ], [
            'expiration.different' => 'O vencimento nao pode ser no mesmo dia do fechamento.',
        ]);

        $card = Card::create([
            'user_id' => Auth::user()->id,
            'flag_id' => $request->flag_id,
            'card_description' => $request->card_description,
            'expiration' => $request->expiration,
            'closure' => $request->closure
        ]);

        LevelController::completeMission(3);

        return response()->json([
            'card' => $card
        ], 201);
    }

    public function showCards(): JsonResponse
    {
        $cards = Auth::user()->cards;
        $response = ['data' => ['cards' => []]];

        foreach ($cards as $card) {
            $card->invoice = $this->invoice($card->id);
            $response['data']['cards'][] = $card;
        }

        return response()->json($response, 200);
    }

    public function showFlags(): JsonResponse
    {
        $flags = Flag::all();
        $response = ['data' => ['flags' => []]];

        foreach ($flags as $flag) {
            $response['data']['flags'][] = $flag;
        }

        return response()->json($response, 200);
    }

    public function invoice($id)
    {
        $card = Card::where('user_id', Auth::id())->find($id);

        if (!$card) {
            return 0;
        }

        $nextPayDay = $this->nextInvoicePayDay($card);

        if (!$nextPayDay) {
            return 0;
        }

        return (float) Installment::where('card_id', $card->id)
            ->whereNull('paid_at')
            ->whereDate('pay_day', $nextPayDay)
            ->sum('installment_value');
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
                'data' => [
                    'invoice' => $invoice,
                    'pay_day' => $nextPayDay
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Cartao nao encontrado.', 404);
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
                'data' => [
                    'pay_day' => $nextPayDay,
                    'installments' => $installments
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Cartao nao encontrado.', 404);
        }
    }

    public function showInvoices($id): JsonResponse
    {
        try {
            $card = Card::where('user_id', Auth::id())->findOrFail($id);

            $installments = Installment::where('card_id', $card->id)
                ->orderBy('pay_day', 'asc')
                ->get();

            $grouped = $installments->groupBy(function ($installment) {
                return Carbon::parse($installment->pay_day)->toDateString();
            });

            $invoices = [];

            foreach ($grouped as $payDay => $items) {
                $openTotal = (float) $items->whereNull('paid_at')->sum('installment_value');
                $lastPaidAt = $items->max('paid_at');

                $invoices[] = [
                    'pay_day' => $payDay,
                    'closure_date' => $this->invoiceClosureDate($card, $payDay)->toDateString(),
                    'total' => (float) $items->sum('installment_value'),
                    'open_total' => $openTotal,
                    'is_paid' => $openTotal <= 0,
                    'installments_count' => $items->count(),
                    'paid_at' => $lastPaidAt
                        ? Carbon::parse($lastPaidAt)
                            ->timezone('America/Sao_Paulo')
                            ->format('Y-m-d H:i:s')
                        : null,
                ];
            }

            return response()->json([
                'data' => [
                    'card_id' => $card->id,
                    'invoices' => $invoices
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Cartao nao encontrado ou sem faturas.', 404);
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
            'payment_method_id.not_in' => 'Pagamento de fatura nao pode ser feito com metodo cartao de credito.',
        ]);

        $invoicePayDay = Carbon::parse($invoicePayDay)->toDateString();
        $userId = Auth::id();

        $installmentsQuery = Installment::where('card_id', $card->id)
            ->whereNull('paid_at')
            ->whereDate('pay_day', $invoicePayDay);

        $openTotal = (float) $installmentsQuery->sum('installment_value');

        if ($openTotal <= 0) {
            return $this->errorResponse('Esta fatura nao possui parcelas em aberto (ja paga ou inexistente).', 422, [
                'invoice_pay_day' => [$invoicePayDay],
            ]);
        }

        $closureDate = $this->invoiceClosureDate($card, $invoicePayDay);
        $today = Carbon::today()->startOfDay();

        if ($today->lt($closureDate)) {
            return $this->errorResponse('A fatura ainda nao fechou. Voce so pode pagar apos o fechamento.', 422, [
                'invoice_pay_day' => [$invoicePayDay],
                'invoice_closure_date' => [$closureDate->toDateString()],
            ]);
        }

        $paymentDate = $request->date ?? Carbon::today()->toDateString();
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
                return $this->errorResponse('Categoria nao encontrada.', 404, [
                    'category_id' => ['Categoria nao encontrada.']
                ]);
            }

            if ((int) $category->type_id !== 2) {
                return $this->errorResponse('A categoria selecionada nao corresponde ao tipo da transacao.', 422, [
                    'category_id' => ['A categoria selecionada nao corresponde ao tipo da transacao.']
                ]);
            }
        }

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

    public function payInvoiceByPayDay(Request $request, $id, $payDay): JsonResponse
    {
        try {
            $card = Card::where('user_id', Auth::id())->findOrFail($id);
            return $this->payInvoiceCore($request, $card, $payDay);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Cartao nao encontrado.', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->validator->errors()->first(), 422, $e->errors());
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao pagar fatura.', 500);
        }
    }

    public function payNextInvoice(Request $request, $id): JsonResponse
    {
        try {
            $card = Card::where('user_id', Auth::id())->findOrFail($id);
            $nextPayDay = $this->nextInvoicePayDay($card);

            if (!$nextPayDay) {
                return $this->errorResponse('Nao ha fatura em aberto para este cartao.', 422);
            }

            return $this->payInvoiceCore($request, $card, $nextPayDay);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Cartao nao encontrado.', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->validator->errors()->first(), 422, $e->errors());
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao pagar fatura.', 500);
        }
    }
}
