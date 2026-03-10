<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Spending;
use Illuminate\Support\Facades\Auth;
use App\Models\Transaction;
use Illuminate\Validation\ValidationException;

class SpendingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'planned_spending' => ['required', 'numeric', 'min:1']
            ], [
                'planned_spending.required' => 'Informe o gasto planejado.',
                'planned_spending.numeric' => 'O gasto planejado deve ser numérico.',
                'planned_spending.min' => 'O gasto planejado deve ser maior que zero.',
            ]);

            $spending = Spending::create([
                'user_id' => Auth::user()->id,
                'planned_spending' => $request->planned_spending,
            ]);

            return response()->json([
                'spending' => $spending
            ], 201);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'data' => [
                    'message' => 'Erro na criação do gasto planejado.',
                    'error' => $e->getMessage()
                ]
            ], 500);
        }
    }


    public function showSpending(): JsonResponse
    {
        try {
            $spending = Spending::where('user_id', Auth::user()->id)
                ->latest()
                ->first('planned_spending');

            $response = [
                'data' => [
                    'spending' => $spending
                ]
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            $errorMessage = 'Erro ao exibit os gastos planejados.';
            $response = [
                'data' => [
                    'message' => $errorMessage,
                    'error' => $e->getMessage()
                ]
            ];

            return response()->json($response, 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'id' => ['required', 'integer'],
                'planned_spending' => ['required', 'numeric', 'min:1']
            ], [
                'planned_spending.required' => 'Informe o gasto planejado.',
                'planned_spending.numeric' => 'O gasto planejado deve ser numérico.',
                'planned_spending.min' => 'O gasto planejado deve ser maior que zero.',
            ]);

            $spending = Spending::find($request->id);

            if (!$spending) {
                return response()->json([
                    'data' => [
                        'message' => 'Gasto planejado não encontrado.'
                    ]
                ], 404);
            }

            $spending->update($request->only('planned_spending'));

            return response()->json([
                'data' => [
                    'spending' => $spending
                ]
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'data' => [
                    'message' => 'Erro ao atualizar os gastos planejados.',
                    'error' => $e->getMessage()
                ]
            ], 500);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'id' => ['required', 'integer']
            ]);

            $spending = Spending::find($request->id);

            if (!$spending) {
                return response()->json([
                    'data' => [
                        'message' => 'Gasto planejado não encontrado.'
                    ]
                ], 404);
            }

            $spending->delete();

            return response()->json([
                'data' => [
                    'message' => 'Gasto planejado deletado com sucesso.'
                ]
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'data' => [
                    'message' => 'Erro ao deletar os gastos planejados.',
                    'error' => $e->getMessage()
                ]
            ], 500);
        }
    }

    public function spendings(Request $request): JsonResponse
    {
        try {
            if ($request->query('sort') == 'day') {

                $spendingByDay = Transaction::where('user_id', Auth::user()->id)
                ->selectRaw('MONTH(date) as month, DAY(date) as day, 
                            SUM(CASE WHEN type_id = 1 THEN transaction_value ELSE 0 END) as incomes,
                            SUM(CASE WHEN type_id = 2 AND payment_method_id != 4 THEN transaction_value ELSE 0 END) as spendings')
                ->groupBy('day')
                ->groupBy('month')
                ->get();

                $response = [
                    'data' => [
                        $spendingByDay,
                    ]
                ];

            } elseif ($request->query('sort') == 'month') {

                $spendingsByMonth = Transaction::where('user_id', Auth::user()->id)
                    ->selectRaw('MONTH(date) as month, YEAR(date) as year,
                                SUM(CASE WHEN type_id = 1 THEN transaction_value ELSE 0 END) as incomes,
                                SUM(CASE WHEN type_id = 2 AND payment_method_id != 4 THEN transaction_value ELSE 0 END) as real_spending')
                    ->groupBy('year')
                    ->groupBy('month')
                    ->get();

                $planned_spendings = Spending::where('user_id', Auth::user()->id)
                    ->selectRaw('MONTH(created_at) as month, YEAR(created_at) as year, planned_spending')
                    ->groupBy('year')
                    ->groupBy('month')
                    ->get();
                

                $plannedMap = [];

                foreach ($planned_spendings as $planned) {
                    $key = $planned->year . '-' . $planned->month;
                    $plannedMap[$key] = $planned->planned_spending;
                }

                foreach ($spendingsByMonth as $item) {
                    $key = $item->year . '-' . $item->month;
                    $item->planned_spending = $plannedMap[$key] ?? null;
                }

                $response = [
                    'data' => [
                        $spendingsByMonth,
                    ]
                ];
            } elseif ($request->query('sort') == 'year') {

                $spendingByYear = Transaction::where('user_id', Auth::user()->id)
                ->selectRaw('YEAR(date) as year, 
                            SUM(CASE WHEN type_id = 1 THEN transaction_value ELSE 0 END) as incomes,
                            SUM(CASE WHEN type_id = 2 AND payment_method_id != 4 THEN transaction_value ELSE 0 END) as spendings')
                ->groupBy('year')
                ->get();

                $response = [
                    'data' => $spendingByYear
                ];

            } else {
                return response()->json([
                    'data' => [
                    'message' => 'Parâmetro sort inválido. Use day, month ou year.'
                    ]
                ], 422);
            }

            return response()->json($response, 200);
        } catch (\Exception $e) {
            $errorMessage = "Erro: Nenhuma entrada foi encontrada.";
            $response = [
                "data" => [
                    "message" => $errorMessage,
                    "error" => $e->getMessage()
                ]
            ];
            return response()->json($response, 500);
        }
    }
}
