<?php

namespace App\Services\Telegram;

use App\Models\Card;
use App\Models\Installment;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

class TelegramCardsQueryService
{
    public function getCardsSummary(int $userId, int $page = 1, int $perPage = 4): array
    {
        $cards = Card::where('user_id', $userId)
            ->orderBy('card_description')
            ->get();

        $total = $cards->count();
        $page = max($page, 1);
        $offset = ($page - 1) * $perPage;
        $pageCards = $cards->slice($offset, $perPage)->values();

        return [
            'page' => $page,
            'per_page' => $perPage,
            'has_previous' => $page > 1,
            'has_more' => ($offset + $perPage) < $total,
            'cards' => $pageCards->map(function (Card $card) {
                $nextInstallment = Installment::query()
                    ->where('card_id', $card->id)
                    ->whereNull('paid_at')
                    ->orderBy('pay_day')
                    ->first();

                $openTotal = (float) Installment::query()
                    ->where('card_id', $card->id)
                    ->whereNull('paid_at')
                    ->sum('installment_value');

                $invoicePayDay = $nextInstallment?->pay_day?->toDateString();
                $invoiceTotal = 0.0;

                if (!is_null($invoicePayDay)) {
                    $invoiceTotal = (float) Installment::query()
                        ->where('card_id', $card->id)
                        ->whereNull('paid_at')
                        ->whereDate('pay_day', $invoicePayDay)
                        ->sum('installment_value');
                }

                return [
                    'card_id' => $card->id,
                    'card_description' => $card->card_description,
                    'closure' => (int) $card->closure,
                    'expiration' => (int) $card->expiration,
                    'open_total' => $openTotal,
                    'invoice_total' => $invoiceTotal,
                    'pay_day' => $invoicePayDay,
                    'closure_date' => $this->invoiceClosureDate($card, $invoicePayDay)?->toDateString(),
                    'has_open_invoice' => !is_null($invoicePayDay) && $invoiceTotal > 0,
                ];
            })->all(),
        ];
    }

    public function getInvoicesSummary(int $userId): array
    {
        $cards = Card::where('user_id', $userId)
            ->orderBy('card_description')
            ->get();

        return [
            'cards' => $cards->map(function (Card $card) {
                $nextInstallment = Installment::query()
                    ->where('card_id', $card->id)
                    ->whereNull('paid_at')
                    ->orderBy('pay_day')
                    ->first();

                $invoicePayDay = $nextInstallment?->pay_day?->toDateString();
                $invoiceTotal = 0.0;

                if (!is_null($invoicePayDay)) {
                    $invoiceTotal = (float) Installment::query()
                        ->where('card_id', $card->id)
                        ->whereNull('paid_at')
                        ->whereDate('pay_day', $invoicePayDay)
                        ->sum('installment_value');
                }

                return [
                    'card_id' => $card->id,
                    'card_description' => $card->card_description,
                    'closure' => (int) $card->closure,
                    'expiration' => (int) $card->expiration,
                    'pay_day' => $invoicePayDay,
                    'closure_date' => $this->invoiceClosureDate($card, $invoicePayDay)?->toDateString(),
                    'open_total' => $invoiceTotal,
                    'has_open_invoice' => !is_null($invoicePayDay) && $invoiceTotal > 0,
                ];
            })->all(),
        ];
    }

    public function getCardInvoiceItems(int $userId, int $cardId, int $page = 1, int $perPage = 5): array
    {
        if ($cardId <= 0) {
            return [
                'invalid_selection' => true,
                'page' => 1,
                'per_page' => $perPage,
                'has_previous' => false,
                'has_more' => false,
                'items' => [],
            ];
        }

        $card = Card::where('user_id', $userId)->findOrFail($cardId);

        $nextInstallment = Installment::query()
            ->where('card_id', $card->id)
            ->whereNull('paid_at')
            ->orderBy('pay_day')
            ->first();

        $invoicePayDay = $nextInstallment?->pay_day?->toDateString();

        if (is_null($invoicePayDay)) {
            return [
                'card_id' => $card->id,
                'card_description' => $card->card_description,
                'pay_day' => null,
                'closure_date' => null,
                'page' => 1,
                'per_page' => $perPage,
                'has_previous' => false,
                'has_more' => false,
                'items' => [],
            ];
        }

        $installments = Installment::query()
            ->with(['transaction.category'])
            ->where('card_id', $card->id)
            ->whereNull('paid_at')
            ->whereDate('pay_day', $invoicePayDay)
            ->orderByDesc('pay_day')
            ->orderByDesc('id')
            ->get();

        $total = $installments->count();
        $page = max($page, 1);
        $offset = ($page - 1) * $perPage;
        $items = $installments->slice($offset, $perPage)->values();

        return [
            'card_id' => $card->id,
            'card_description' => $card->card_description,
            'pay_day' => $invoicePayDay,
            'closure_date' => $this->invoiceClosureDate($card, $invoicePayDay)?->toDateString(),
            'page' => $page,
            'per_page' => $perPage,
            'has_previous' => $page > 1,
            'has_more' => ($offset + $perPage) < $total,
            'items' => $items->map(function (Installment $installment) {
                $transaction = $installment->transaction;
                $installmentsCount = (int) ($transaction?->installments ?? 1);

                return [
                    'installment_id' => $installment->id,
                    'description' => $transaction?->transaction_description ?? $installment->installment_description,
                    'date' => $transaction?->date,
                    'category_description' => $transaction?->category?->category_description ?? '-',
                    'value' => (float) $installment->installment_value,
                    'installments_label' => $installmentsCount > 1 ? ($installment->installment_description ?? ($installmentsCount . 'x')) : '1x',
                ];
            })->all(),
        ];
    }

    private function invoiceClosureDate(Card $card, ?string $invoicePayDay): ?Carbon
    {
        if (is_null($invoicePayDay)) {
            return null;
        }

        $payDay = Carbon::parse($invoicePayDay);
        $closureDay = (int) $card->closure;
        $expirationDay = (int) $card->expiration;

        $closureMonth = $expirationDay > $closureDay
            ? $payDay->copy()
            : $payDay->copy()->subMonth();

        return Carbon::create(
            $closureMonth->year,
            $closureMonth->month,
            min($closureDay, $closureMonth->daysInMonth)
        );
    }
}
