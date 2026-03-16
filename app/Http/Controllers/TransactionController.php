<?php

namespace App\Http\Controllers;

use App\Exceptions\TransactionCreationException;
use App\Models\Card;
use App\Models\Category;
use App\Models\Installment;
use App\Models\Transaction;
use App\Services\Transactions\TransactionCreationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionCreationService $transactionCreationService
    ) {
    }

    private function resolveFirstInstallmentPayDay(string $transactionDate, Card $card): string
    {
        $purchaseDate = Carbon::parse($transactionDate)->startOfDay();

        $closureDay = (int) $card->closure;
        $expirationDay = (int) $card->expiration;

        $closureThisMonth = Carbon::create(
            $purchaseDate->year,
            $purchaseDate->month,
            min($closureDay, $purchaseDate->daysInMonth),
            0,
            0,
            0
        );

        if ($expirationDay > $closureDay) {
            $baseMonth = $purchaseDate->lte($closureThisMonth)
                ? $purchaseDate->copy()
                : $purchaseDate->copy()->addMonth();
        } else {
            $baseMonth = $purchaseDate->lte($closureThisMonth)
                ? $purchaseDate->copy()->addMonth()
                : $purchaseDate->copy()->addMonths(2);
        }

        $dueDate = Carbon::create(
            $baseMonth->year,
            $baseMonth->month,
            min($expirationDay, $baseMonth->daysInMonth),
            0,
            0,
            0
        );

        return $dueDate->toDateString();
    }

    private function buildInstallmentDates(string $firstPayDay, int $installments): array
    {
        $dates = [];
        $baseDate = Carbon::parse($firstPayDay)->startOfDay();

        for ($i = 0; $i < $installments; $i++) {
            $dates[] = $baseDate->copy()->addMonths($i)->toDateString();
        }

        return $dates;
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $result = $this->transactionCreationService->create(Auth::id(), $request->all());

            return response()->json([
                'data' => [
                    'transaction' => $result['transaction'],
                    'installments' => $result['installments'],
                ]
            ], 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Os dados informados sao invalidos.', 422, $e->errors());
        } catch (TransactionCreationException $e) {
            return $this->errorResponse($e->getMessage(), $e->status(), $e->errors());
        } catch (\Throwable $e) {
            return $this->errorResponse('Erro ao criar a transacao.', 500);
        }
    }

    public function showTransactions(): JsonResponse
    {
        $transactions = Transaction::orderBy('date', 'desc')
            ->where('user_id', Auth::id())
            ->get();

        $mostExpensiveTransaction = Transaction::where([
            'user_id' => Auth::id(),
            'type_id' => 2,
        ])->max('transaction_value') ?? 0;

        $response = ['data' => ['transactions' => []], 'most_expensive' => $mostExpensiveTransaction, 'total' => count($transactions)];

        foreach ($transactions as $transaction) {
            $transaction->category_description = Category::find($transaction->category_id)?->category_description;
            $response['data']['transactions'][] = $transaction;
        }

        return response()->json($response, 200);
    }

    public function showTransaction($id): JsonResponse
    {
        try {
            $transaction = Transaction::where('user_id', Auth::id())->findOrFail($id);
            $transaction->category_description = Category::find($transaction->category_id)?->category_description;

            return response()->json([
                'data' => [
                    'transaction' => $transaction
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Erro: Transacao nao encontrada.', 404);
        }
    }

    public function incomes(): JsonResponse
    {
        return $this->showTransactionsByType(1);
    }

    public function showTransactionsByType($id): JsonResponse
    {
        $transactions = Transaction::where([
            'user_id' => Auth::user()->id,
            'type_id' => $id
        ])->orderBy('date', 'desc')->get();

        $response = ['data' => ['transactions' => []], 'total' => count($transactions)];

        foreach ($transactions as $transaction) {
            $transaction->category_description = Category::find($transaction->category_id)?->category_description;
            $response['data']['transactions'][] = $transaction;
        }

        return response()->json($response, 200);
    }

    public function showTransactionsByCard($id): JsonResponse
    {
        $transactions = Transaction::where([
            'card_id' => $id,
            'user_id' => Auth::user()->id
        ])->get();

        $response = ['data' => ['transactions' => []]];

        foreach ($transactions as $transaction) {
            $transaction->category_description = Category::find($transaction->category_id)?->category_description;
            $response['data']['transactions'][] = $transaction;
        }

        return response()->json($response, 200);
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $transaction = Transaction::where('user_id', Auth::id())->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Essa transacao nao existe ou ja foi excluida.', 404);
        }

        try {
            $request->validate([
                'transaction_description' => ['string', 'max:50'],
                'category_id' => ['integer', 'min:0'],
                'category_description' => ['required_if:category_id,0', 'string', 'max:50'],
                'date' => ['date', 'before_or_equal:today'],
                'transaction_value' => ['numeric', 'min:0.01'],
                'payment_method_id' => ['integer', 'min:1', 'max:4'],
                'installments' => ['integer', 'min:1'],
            ], [
                'date.before_or_equal' => 'O campo data deve ser uma data anterior ou igual a data atual.',
                'transaction_value.numeric' => 'Informe um valor numerico valido para a transacao.',
                'transaction_value.min' => 'Informe um valor de transacao maior que zero.',
                'installments.integer' => 'O campo parcelas deve ser um numero inteiro.',
                'installments.min' => 'O numero de parcelas deve ser no minimo 1.',
            ]);

            $categoryIdToUpdate = $transaction->category_id;

            if (!is_null($request->category_id)) {
                if ((int) $request->category_id === 0) {
                    $category = CategoryController::storeInTransaction(
                        $request->category_description,
                        $transaction->type_id
                    );
                } else {
                    $category = Category::find($request->category_id);
                }

                if (!$category) {
                    return $this->errorResponse('Categoria nao encontrada.', 404, [
                        'category_id' => ['Categoria nao encontrada.']
                    ]);
                }

                if ((int) $category->type_id !== (int) $transaction->type_id) {
                    return $this->errorResponse('A categoria selecionada nao corresponde ao tipo da transacao.', 422, [
                        'category_id' => ['A categoria selecionada nao corresponde ao tipo da transacao.']
                    ]);
                }

                $categoryIdToUpdate = $category->id;
            }

            $transaction->update([
                'transaction_description' => $request->transaction_description ?? $transaction->transaction_description,
                'category_id' => $categoryIdToUpdate,
                'date' => $request->date ?? $transaction->date,
                'transaction_value' => $request->transaction_value ?? $transaction->transaction_value,
                'payment_method_id' => $request->payment_method_id ?? $transaction->payment_method_id,
                'installments' => $request->installments ?? $transaction->installments,
            ]);

            $transaction = Transaction::where('user_id', Auth::id())->findOrFail($id);

            if ($transaction->payment_method_id == 4) {
                if (!is_null($request->installments)) {
                    Installment::where('transaction_id', $id)->delete();

                    $card = Card::where('user_id', Auth::id())->findOrFail($transaction->card_id);
                    $installmentsCount = (int) $request->installments;
                    $firstPayDay = $this->resolveFirstInstallmentPayDay($transaction->date, $card);
                    $payDays = $this->buildInstallmentDates($firstPayDay, $installmentsCount);

                    $transactionValue = (float) $transaction->transaction_value;
                    $value = (float) number_format($transactionValue / $installmentsCount, 2, '.', '');
                    $firstInstallment = $transactionValue - ($value * ($installmentsCount - 1));
                    $firstInstallment = (float) number_format($firstInstallment, 2, '.', '');

                    for ($i = 1; $i <= $installmentsCount; $i++) {
                        $installmentValue = ($i === 1) ? $firstInstallment : $value;

                        Installment::create([
                            'transaction_id' => $id,
                            'installment_description' => $transaction->transaction_description . ' ' . $i . '/' . $installmentsCount,
                            'installment_value' => $installmentValue,
                            'card_id' => $transaction->card_id,
                            'pay_day' => $payDays[$i - 1]
                        ]);
                    }
                }

                if (!is_null($request->transaction_value) && is_null($request->installments)) {
                    $installmentsCount = (int) $transaction->installments;
                    $transactionValue = (float) $transaction->transaction_value;
                    $value = (float) number_format($transactionValue / $installmentsCount, 2, '.', '');
                    $firstInstallment = $transactionValue - ($value * ($installmentsCount - 1));
                    $firstInstallment = (float) number_format($firstInstallment, 2, '.', '');

                    $installmentsCollection = Installment::where('transaction_id', $id)
                        ->orderBy('id')
                        ->get()
                        ->values();

                    foreach ($installmentsCollection as $index => $installment) {
                        $installmentValue = ($index === 0) ? $firstInstallment : $value;
                        $installment->update([
                            'installment_value' => $installmentValue
                        ]);
                    }
                }

                if (!is_null($request->transaction_description)) {
                    $count = 1;
                    Installment::where('transaction_id', $id)
                        ->orderBy('id')
                        ->get()
                        ->each(function ($installment) use ($request, &$count, $transaction) {
                            $installment->update([
                                'installment_description' => $request->transaction_description . ' ' . $count . '/' . $transaction->installments,
                            ]);

                            $count++;
                        });
                }

                if (!is_null($request->date) && is_null($request->installments)) {
                    $card = Card::where('user_id', Auth::id())->findOrFail($transaction->card_id);
                    $firstPayDay = $this->resolveFirstInstallmentPayDay($request->date, $card);
                    $payDays = $this->buildInstallmentDates($firstPayDay, (int) $transaction->installments);

                    $index = 0;
                    Installment::where('transaction_id', $id)
                        ->orderBy('id')
                        ->get()
                        ->each(function ($installment) use (&$index, $payDays) {
                            $installment->update([
                                'pay_day' => $payDays[$index]
                            ]);
                            $index++;
                        });
                }
            }

            $installments = Installment::where('transaction_id', $id)->get();

            return response()->json([
                'data' => [
                    'transaction' => $transaction,
                    'installments' => $installments
                ]
            ], 200);

        } catch (ValidationException $e) {
            return $this->errorResponse('Os dados informados sao invalidos.', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao atualizar a transacao.', 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            DB::transaction(function () use ($id) {
                $transaction = Transaction::where('user_id', Auth::id())->findOrFail($id);

                Installment::where('payment_transaction_id', $id)->update([
                    'paid_at' => null,
                    'payment_transaction_id' => null
                ]);

                Installment::where('transaction_id', $id)->update([
                    'paid_at' => null,
                    'payment_transaction_id' => null
                ]);

                Installment::where('transaction_id', $id)->delete();

                $transaction->delete();
            });

            return response()->json([
                'data' => [
                    'message' => 'Transacao excluida com sucesso.'
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Erro: Esta transacao nao existe.', 404);
        }
    }
}
