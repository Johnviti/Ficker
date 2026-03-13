<?php

namespace App\Services\Telegram;

use App\Models\Card;
use App\Models\Installment;
use App\Models\Spending;
use App\Models\Transaction;

class TelegramFinancialQueryService
{
    public function getBalance(int $userId): array
    {
        $month = now()->month;
        $year = now()->year;

        $incomes = Transaction::where('user_id', $userId)
            ->where('type_id', 1)
            ->sum('transaction_value');

        $paidOutgoings = Transaction::where('user_id', $userId)
            ->where('type_id', 2)
            ->where(function ($query) {
                $query->whereNull('payment_method_id')
                    ->orWhere('payment_method_id', '!=', 4);
            })
            ->sum('transaction_value');

        $realSpending = Transaction::where('user_id', $userId)
            ->where('type_id', 2)
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->where(function ($query) {
                $query->whereNull('payment_method_id')
                    ->orWhere('payment_method_id', '!=', 4);
            })
            ->sum('transaction_value');

        $plannedSpending = (float) (Spending::where('user_id', $userId)->latest()->value('planned_spending') ?? 0);

        return [
            'balance' => (float) $incomes - (float) $paidOutgoings,
            'real_spending' => (float) $realSpending,
            'planned_spending' => $plannedSpending,
        ];
    }

    public function getNextInvoice(int $userId): array
    {
        $nextInstallment = Installment::query()
            ->select(['card_id', 'pay_day'])
            ->whereNull('paid_at')
            ->whereHas('transaction', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->orderBy('pay_day', 'asc')
            ->first();

        if (!$nextInstallment) {
            return [
                'has_open_invoice' => false,
            ];
        }

        $card = Card::where('user_id', $userId)->find($nextInstallment->card_id);

        if (!$card) {
            return [
                'has_open_invoice' => false,
            ];
        }

        $total = (float) Installment::where('card_id', $card->id)
            ->whereNull('paid_at')
            ->whereDate('pay_day', $nextInstallment->pay_day)
            ->sum('installment_value');

        return [
            'has_open_invoice' => true,
            'card_id' => $card->id,
            'card_description' => $card->card_description,
            'pay_day' => $nextInstallment->pay_day->toDateString(),
            'total' => $total,
        ];
    }

    public function getLastTransactions(int $userId, int $limit = 5): array
    {
        $transactions = Transaction::with('category')
            ->where('user_id', $userId)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        return [
            'transactions' => $transactions->map(function (Transaction $transaction) {
                return [
                    'id' => $transaction->id,
                    'description' => $transaction->transaction_description,
                    'value' => (float) $transaction->transaction_value,
                    'date' => $transaction->date,
                    'type_id' => (int) $transaction->type_id,
                    'category_description' => $transaction->category?->category_description,
                ];
            })->all(),
        ];
    }
}
