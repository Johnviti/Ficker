<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Models\Transaction;
use App\Models\Category;
use App\Models\Card;
use App\Models\Installment;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'transaction_description' => ['required', 'string', 'max:50'],
            'category_id' => ['required', 'integer', 'min:0'],
            'category_description' => ['required_if:category_id,0', 'string', 'max:50'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'type_id' => ['required', 'integer', 'min:1', 'max:2'],
            'transaction_value' => ['required', 'numeric', 'min:0.01'],
            'payment_method_id' => ['required_if:type_id,2', 'prohibited_if:type_id,1', 'integer'],
            'installments' => ['required_if:payment_method_id,4', 'prohibited_unless:payment_method_id,4', 'integer', 'min:1'],
            'card_id' => ['required_if:payment_method_id,4', 'prohibited_unless:payment_method_id,4', 'integer']
        ], [
            'installments.required_if' => 'O campo parcelas é obrigatório para compras no cartão de crédito.',
            'installments.integer' => 'O campo parcelas deve ser um número inteiro.',
            'installments.min' => 'O número de parcelas deve ser no mínimo 1.',
            'card_id.required_if' => 'O cartão é obrigatório para compras no cartão de crédito.',
            'transaction_value.numeric' => 'Informe um valor numérico válido para a transação.',
            'transaction_value.min' => 'Informe um valor de transação maior que zero.'
        ]);

        // Validando card id

        if ($request->payment_method_id == 4) {

            try {

                Card::findOrFail($request->card_id);
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Cartão não encontrado.',
                    'errors' => [
                        'card_id' => ['Cartão não encontrado.']
                    ]
                ], 404);
            }
        }

        // Cadastrando nova categoria

        if ($request->category_id == 0) {

            $category = CategoryController::storeInTransaction($request->category_description, $request->type_id);

        } else {

            $category = Category::find($request->category_id);
        }

        if (!$category) {
            return response()->json([
                'message' => 'Categoria não encontrada.',
                'errors' => [
                    'category_id' => ['Categoria não encontrada.']
                ]
            ], 404);
        }

        if ((int) $category->type_id !== (int) $request->type_id) {
            return response()->json([
                'message' => 'A categoria selecionada não corresponde ao tipo da transação.',
                'errors' => [
                    'category_id' => ['A categoria selecionada não corresponde ao tipo da transação.']
                ]
            ], 422);
        }

        // Cadastrando transação

        if (is_null($request->installments)) { // Entrada e saída sem parcelas

            $transaction = Transaction::create([
                'user_id' => Auth::user()->id,
                'category_id' => $category->id,
                'type_id' => $request->type_id,
                'payment_method_id' => $request->payment_method_id,
                'transaction_description' => $request->transaction_description,
                'date' => $request->date,
                'transaction_value' => $request->transaction_value,
            ]);

            LevelController::completeMission($request->type_id);

            $response = [
                'data' => [
                    'transaction' => $transaction
                ]
            ];

            return response()->json($response, 201);

        } else { // Saídas de cartão de crédito

            if ((int) $request->installments < 1) {
                return response()->json([
                    'message' => 'O número de parcelas deve ser no mínimo 1.',
                    'errors' => [
                        'installments' => ['O número de parcelas deve ser no mínimo 1.']
                    ]
                ], 422);
            }

            $transaction = Transaction::create([
                'user_id' => Auth::user()->id,
                'category_id' => $category->id,
                'type_id' => $request->type_id,
                'payment_method_id' => $request->payment_method_id,
                'card_id' => $request->card_id,
                'transaction_description' => $request->transaction_description,
                'date' => $request->date,
                'transaction_value' => $request->transaction_value,
                'installments' => $request->installments,
            ]);

            $response = [];
            $pay_day = $request->date;
            $new_pay_day_formated = $pay_day;
            $installments = (int) $request->installments;
            $transactionValue = (float) $request->transaction_value;
            $value = (float) number_format($transactionValue / $installments, 2, '.', '');
            $firstInstallment = $transactionValue - ($value * ($installments - 1));
            $firstInstallment = (float) number_format($firstInstallment, 2, '.', '');

            for ($i = 1; $i <= $installments; $i++) {

                if ($i == 1) {
                    $installment = Installment::create([
                        'transaction_id' => $transaction->id,
                        'installment_description' => $request->transaction_description . ' ' . $i . '/' . $installments,
                        'installment_value' => $firstInstallment,
                        'card_id' => $request->card_id,
                        'pay_day' => $pay_day
                    ]);

                    array_push($response, $installment);
                } else {
                    $new_pay_day = strtotime('+1 months', strtotime($pay_day));
                    $new_pay_day_formated = date('Y-m-d', $new_pay_day);
                    $installment = Installment::create([
                        'transaction_id' => $transaction->id,
                        'installment_description' => $request->transaction_description . ' ' . $i . '/' . $installments,
                        'installment_value' => $value,
                        'card_id' => $request->card_id,
                        'pay_day' => $new_pay_day_formated
                    ]);

                    array_push($response, $installment);
                }

                $pay_day = $new_pay_day_formated;
            }

            LevelController::completeMission(4);

            return response()->json($response, 200);
        }
    }

    public function showTransactions(): JsonResponse
    {
        try {
            $transactions = Transaction::orderBy('date', 'desc')
                ->where('user_id', Auth::id())
                ->get();

            $most_expensive_transaction = Transaction::where([
                    'user_id' => Auth::id(),
                    'type_id' => 2,
                ])
                ->max('transaction_value') ?? 0;

            $response = ['data' => ['transactions' => []], 'most_expensive' => $most_expensive_transaction, 'total' => count($transactions)];

            foreach($transactions as $transaction) {
                $description = Category::find($transaction->category_id)?->category_description;
                $transaction->category_description = $description;
                array_push($response['data']['transactions'], $transaction);
            }

            return response()->json($response, 200);

        } catch (\Exception $e) {
            $errorMessage = 'Nenhuma transação foi encontrada';
            $response = [
                "data" => [
                    "message" => $errorMessage,
                    "error" => $e->getMessage()
                ]
            ];

            return response()->json($response, 404);
        }
    }

    public function showTransaction($id): JsonResponse
    {
        try {

            $transaction = Transaction::findOrFail($id);

            $description = Category::find($transaction->category_id)?->category_description;
            $transaction->category_description = $description;

            $response = [
                'data' => [
                    'transaction' => $transaction
                ]
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            $errorMessage = "Erro: Transação não encontrada.";
            $response = [
                "data" => [
                    "message" => $errorMessage,
                    "error" => $e->getMessage()
                ]
            ];
            return response()->json($response, 404);
        }
    }

    public function showTransactionsByType($id): JsonResponse
    {
        try {
            
            $transactions = Transaction::where([
                'user_id' => Auth::user()->id,
                'type_id' => $id
            ])->orderBy('date', 'desc')->get();

            $response = ['data' => ['transactions' => []], 'total' => count($transactions)];

            foreach ($transactions  as $transaction) {
                $description = Category::find($transaction->category_id)?->category_description;
                $transaction->category_description = $description;
                array_push($response['data']['transactions'], $transaction);
            }

            return response()->json($response, 200);
        } catch (\Exception $e) {

            $errorMessage = "Erro: Nenhuma transação encontrada.";
            $response = [
                "data" => [
                    "message" => $errorMessage,
                    "error" => $e->getMessage()
                ]
            ];
            return response()->json($response, 404);
        }
    }

    public function showTransactionsByCard($id): JsonResponse
    {
        try {

            $transactions = Transaction::where([
                'card_id' => $id,
                'user_id' => Auth::user()->id
            ])->get();

            $response = ['data' => ['transactions' => []]];
            foreach ($transactions as $transaction) {
                $description = Category::find($transaction->category_id)?->category_description;
                $transaction->category_description = $description;
                array_push($response['data']['transactions'], $transaction);
            }

            return response()->json($response, 200);
        } catch (\Exception $e) {
            $errorMessage = "Erro: Este cartão não possui transações.";
            $response = [
                "data" => [
                    "message" => $errorMessage,
                    "error" => $e->getMessage()
                ]
            ];
            return response()->json($response, 404);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            Transaction::findOrFail($request->id);
        } catch (\Exception $e) {

            $errorMessage = "Essa transação não existe ou já foi excluída.";
            $response = [
                "data" => [
                    "message" => $errorMessage,
                    "error" => $e->getMessage()
                ]
            ];
            return response()->json($response, 404);
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
            ]);

            $transaction = Transaction::find($request->id);

            $categoryIdToUpdate = $transaction->category_id;

            // Tratando categoria no update com a mesma lógica do store
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
                return     response()->json([
                        'message' => 'Categoria não encontrada.',
                        'errors' => [
                            'category_id' => ['Categoria não encontrada.']
                        ]
                    ], 404);
                }

                if ((int) $category->type_id !== (int) $transaction->type_id) {
                return     response()->json([
                        'message' => 'A categoria selecionada não corresponde ao tipo da transação.',
                        'errors' => [
                            'category_id' => ['A categoria selecionada não corresponde ao tipo da transação.']
                        ]
                    ], 422);
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

            $transaction = Transaction::find($request->id);

            if ($transaction->payment_method_id == 4) {

                if (!(is_null($request->installments))) {

                    Installment::where('transaction_id', $request->id)->delete();
                    $date = $transaction->date;

                    for ($i = 1; $i <= $request->installments; $i++) {

                        Installment::create([
                            'transaction_id' => $request->id,
                            'installment_description' => $transaction->transaction_description . ' ' . $i . '/' . $request->installments,
                            'installment_value' => $transaction->transaction_value / $request->installments,
                            'card_id' => $transaction->card_id,
                            'pay_day' => $date
                        ]);

                        $date = strtotime('+1 months', strtotime($date));
                        $date = date('Y-m-d', $date);
                    }
                }

                if (!(is_null($request->transaction_value))) {

                    Installment::where('transaction_id', $request->id)->get()->each(function ($installment) use ($request) {

                        $transaction = Transaction::find($request->id);

                        $installment->update([
                            'installment_value' => $request->transaction_value / $transaction->installments
                        ]);
                    });
                }

                if (!(is_null($request->transaction_description))) {

                    $count = 1;
                    Installment::where('transaction_id', $request->id)->get()->each(function ($installment) use ($request, &$count) {

                        $transaction = Transaction::find($request->id);

                        $installment->update([
                            'installment_description' => $request->transaction_description . ' ' . $count . '/' . $transaction->installments,
                        ]);

                        $count++;
                    });
                }

                if (!(is_null($request->date))) {

                    $date = $request->date;
                    Installment::where('transaction_id', $request->id)->get()->each(function ($installment) use (&$date) {

                        $installment->update([
                            'pay_day' => $date,
                        ]);

                        $date = strtotime('+1 months', strtotime($date));
                        $date = date('Y-m-d', $date);
                    });
                }
            }

            $installments = Installment::where('transaction_id', $request->id)->get();

            $response = [
                "transaction" => $transaction,
                "installments" => $installments
            ];

            return response()->json($response, 200);

        } catch (\Exception $e) {

            $errorMessage = $e->getMessage();
            $response = [
                "data" => [
                    "message" => $errorMessage,
                ]
            ];
            return response()->json($response, 400);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            DB::transaction(function () use ($id) {
                $transaction = Transaction::findOrFail($id);
                Installment::where('transaction_id', $id)->delete();
                $transaction->delete();
            });

            $response = [
                'data' => [
                    'message' => 'Transação excluída com sucesso.'
                ]
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            $response = [
                "data" => [
                    "message" => "Erro: Esta transação não existe.",
                    "error" => $e->getMessage()
                ]
            ];

            return response()->json($response, 404);
        }
    }

}
