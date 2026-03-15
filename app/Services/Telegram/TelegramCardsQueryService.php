<?php

namespace App\Services\Telegram;

use App\Models\Card;
use App\Models\Installment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class TelegramCardsQueryService
{
    public function getCardsSummary(int $userId, int $page = 1, int $perPage = 4): array
    {
        $cards = Card::query()
            ->with('flag')
            ->where('user_id', $userId)
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
            'cards' => $pageCards->map(fn (Card $card) => $this->buildCardSummary($card))->all(),
        ];
    }

    public function getCardDetails(int $userId, int $cardId): array
    {
        if ($cardId <= 0) {
            return [
                'invalid_selection' => true,
            ];
        }

        $card = Card::query()
            ->with('flag')
            ->where('user_id', $userId)
            ->findOrFail($cardId);

        return $this->buildCardSummary($card);
    }

    public function getCardInvoices(int $userId, int $cardId, int $page = 1, int $perPage = 4): array
    {
        $card = Card::query()
            ->where('user_id', $userId)
            ->findOrFail($cardId);

        $installments = Installment::query()
            ->where('card_id', $card->id)
            ->orderBy('pay_day', 'asc')
            ->get();

        $grouped = $installments
            ->groupBy(fn (Installment $installment) => Carbon::parse($installment->pay_day)->toDateString())
            ->map(function (Collection $items, string $payDay) use ($card) {
                $openTotal = (float) $items->whereNull('paid_at')->sum('installment_value');
                $total = (float) $items->sum('installment_value');
                $closureDate = $this->invoiceClosureDate($card, $payDay)?->toDateString();
                $paidAt = $items->max('paid_at');

                return [
                    'pay_day' => $payDay,
                    'closure_date' => $closureDate,
                    'total' => $total,
                    'open_total' => $openTotal,
                    'installments_count' => $items->count(),
                    'is_paid' => $openTotal <= 0,
                    'paid_at' => $paidAt
                        ? Carbon::parse($paidAt)->timezone('America/Sao_Paulo')->format('Y-m-d H:i:s')
                        : null,
                    'status' => $this->resolveInvoiceStatus($openTotal, $closureDate),
                ];
            })
            ->values();

        $total = $grouped->count();
        $page = max($page, 1);
        $offset = ($page - 1) * $perPage;
        $invoices = $grouped->slice($offset, $perPage)->values();

        return [
            'card_id' => $card->id,
            'card_description' => $card->card_description,
            'page' => $page,
            'per_page' => $perPage,
            'has_previous' => $page > 1,
            'has_more' => ($offset + $perPage) < $total,
            'invoices' => $invoices->all(),
        ];
    }

    public function getCardInvoiceItems(
        int $userId,
        int $cardId,
        ?string $payDay = null,
        int $page = 1,
        int $perPage = 5
    ): array {
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

        $card = Card::query()
            ->where('user_id', $userId)
            ->findOrFail($cardId);

        $invoicePayDay = blank($payDay) ? null : $payDay;

        if (is_null($invoicePayDay)) {
            $nextInstallment = Installment::query()
                ->where('card_id', $card->id)
                ->whereNull('paid_at')
                ->orderBy('pay_day')
                ->first();

            $invoicePayDay = $nextInstallment?->pay_day?->toDateString();
        }

        if (is_null($invoicePayDay)) {
            return [
                'card_id' => $card->id,
                'card_description' => $card->card_description,
                'pay_day' => null,
                'closure_date' => null,
                'invoice_total' => 0,
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
            ->whereDate('pay_day', $invoicePayDay)
            ->orderByDesc('id')
            ->get();

        $total = $installments->count();
        $page = max($page, 1);
        $offset = ($page - 1) * $perPage;
        $items = $installments->slice($offset, $perPage)->values();
        $invoiceTotal = (float) $installments->sum('installment_value');
        $openTotal = (float) $installments->whereNull('paid_at')->sum('installment_value');
        $closureDate = $this->invoiceClosureDate($card, $invoicePayDay)?->toDateString();

        return [
            'card_id' => $card->id,
            'card_description' => $card->card_description,
            'pay_day' => $invoicePayDay,
            'closure_date' => $closureDate,
            'invoice_total' => $invoiceTotal,
            'open_total' => $openTotal,
            'is_paid' => $openTotal <= 0,
            'status' => $this->resolveInvoiceStatus($openTotal, $closureDate),
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
                    'installments_label' => $installmentsCount > 1
                        ? ($installment->installment_description ?? ($installmentsCount . 'x'))
                        : '1x',
                    'is_paid' => !is_null($installment->paid_at),
                ];
            })->all(),
        ];
    }

    private function buildCardSummary(Card $card): array
    {
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

        $closureDate = $this->invoiceClosureDate($card, $invoicePayDay)?->toDateString();

        return [
            'card_id' => $card->id,
            'card_description' => $card->card_description,
            'flag_description' => $card->flag?->flag_description,
            'closure' => (int) $card->closure,
            'expiration' => (int) $card->expiration,
            'open_total' => $openTotal,
            'invoice_total' => $invoiceTotal,
            'pay_day' => $invoicePayDay,
            'closure_date' => $closureDate,
            'has_open_invoice' => !is_null($invoicePayDay) && $invoiceTotal > 0,
            'can_pay_invoice' => !is_null($invoicePayDay)
                && $invoiceTotal > 0
                && !is_null($closureDate)
                && Carbon::today()->startOfDay()->gte(Carbon::parse($closureDate)->startOfDay()),
            'invoice_status' => $this->resolveInvoiceStatus($invoiceTotal, $closureDate),
        ];
    }

    private function resolveInvoiceStatus(float $openTotal, ?string $closureDate): string
    {
        if ($openTotal <= 0) {
            return 'Paga';
        }

        if (is_null($closureDate)) {
            return 'Sem fatura';
        }

        return Carbon::today()->startOfDay()->gte(Carbon::parse($closureDate)->startOfDay())
            ? 'Disponivel para pagamento'
            : 'Aguardando fechamento';
    }

    private function invoiceClosureDate(Card $card, ?string $invoicePayDay): ?Carbon
    {
        if (is_null($invoicePayDay)) {
            return null;
        }

        $payDay = Carbon::parse($invoicePayDay)->startOfDay();
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
