<?php

namespace App\Services\Telegram;

use App\Models\Transaction;

class TelegramTransactionsPaginationService
{
    public function getPage(int $userId, int $page = 1, int $perPage = 5): array
    {
        $safePage = max($page, 1);
        $safePerPage = max($perPage, 1);

        $query = Transaction::with('category')
            ->where('user_id', $userId)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

        $total = (clone $query)->count();
        $transactions = $query
            ->forPage($safePage, $safePerPage)
            ->get();

        return [
            'page' => $safePage,
            'per_page' => $safePerPage,
            'has_previous' => $safePage > 1,
            'has_more' => ($safePage * $safePerPage) < $total,
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
