<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Spending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use App\Models\Installment;

class BalanceController extends Controller
{
    public function balance(): JsonResponse
    {
        try {
            $userId = Auth::user()->id;
            $month = now()->month;
            $year = now()->year;

            $incomes = Transaction::where('user_id', $userId)
                ->where('type_id', 1)
                ->sum('transaction_value');

            // Saídas efetivamente pagas no histórico geral
            $paidOutgoings = Transaction::where('user_id', $userId)
                ->where('type_id', 2)
                ->where(function ($query) {
                    $query->whereNull('payment_method_id')
                        ->orWhere('payment_method_id', '!=', 4);
                })
                ->sum('transaction_value');

            $balance = $incomes - $paidOutgoings;

            // Gasto real do mês = o que efetivamente foi pago no mês
            $real_spending = Transaction::where('user_id', $userId)
                ->where('type_id', 2)
                ->whereMonth('date', $month)
                ->whereYear('date', $year)
                ->where(function ($query) {
                    $query->whereNull('payment_method_id')
                        ->orWhere('payment_method_id', '!=', 4);
                })
                ->sum('transaction_value');

            $spending = Spending::where('user_id', $userId)
                ->latest()
                ->first();

            if (!$spending) {
                $spending = new Spending();
                $spending->user_id = $userId;
                $spending->planned_spending = 0;
            }

            $spending->real_spending = $real_spending;
            $spending->balance = $balance;

            $response = [
                'finances' => $spending
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            $errorMessage = 'Erro ao exibir o saldo.';
            $response = [
                'data' => [
                    'message' => $errorMessage,
                    'error' => $e->getMessage()
                ]
            ];

            return response()->json($response, 500);
        }
    }
}
