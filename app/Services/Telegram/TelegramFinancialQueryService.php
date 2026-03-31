<?php

namespace App\Services\Telegram;

use App\Models\Card;
use App\Models\Spending;
use App\Models\Transaction;

class TelegramFinancialQueryService
{
    public function __construct(
        private readonly \App\Services\Cards\CardInvoiceSummaryService $cardInvoiceSummaryService
    ) {
    }

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
        $cards = Card::query()
            ->where('user_id', $userId)
            ->orderBy('card_description')
            ->get();

        $nextInvoice = $cards
            ->map(function (Card $card) {
                $summary = $this->cardInvoiceSummaryService->currentInvoice($card);

                return [
                    'card' => $card,
                    'pay_day' => $summary['pay_day'] ?? null,
                    'open_total' => (float) ($summary['open_total'] ?? 0),
                ];
            })
            ->filter(fn (array $item) => !is_null($item['pay_day']) && $item['open_total'] > 0)
            ->sortBy(fn (array $item) => (string) $item['pay_day'])
            ->first();

        if (!$nextInvoice) {
            return [
                'has_open_invoice' => false,
            ];
        }

        /** @var Card $card */
        $card = $nextInvoice['card'];

        return [
            'has_open_invoice' => true,
            'card_id' => $card->id,
            'card_description' => $card->card_description,
            'pay_day' => $nextInvoice['pay_day'],
            'total' => $nextInvoice['open_total'],
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
