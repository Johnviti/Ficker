<?php

namespace App\Services\Telegram;

use App\Models\Card;
use App\Models\Installment;
use Illuminate\Support\Carbon;

class TelegramCardsQueryService
{
    public function getCardsSummary(int $userId): array
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

                $openTotal = (float) Installment::query()
                    ->where('card_id', $card->id)
                    ->whereNull('paid_at')
                    ->sum('installment_value');

                return [
                    'card_id' => $card->id,
                    'card_description' => $card->card_description,
                    'closure' => (int) $card->closure,
                    'expiration' => (int) $card->expiration,
                    'open_total' => $openTotal,
                    'next_pay_day' => $nextInstallment?->pay_day?->toDateString(),
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
