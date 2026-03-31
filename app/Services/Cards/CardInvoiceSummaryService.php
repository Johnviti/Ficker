<?php

namespace App\Services\Cards;

use App\Models\Card;
use App\Models\CardInvoicePayment;
use App\Models\Installment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CardInvoiceSummaryService
{
    public function nextOpenInvoicePayDay(Card $card): ?string
    {
        return Installment::query()
            ->where('card_id', $card->id)
            ->select('pay_day')
            ->distinct()
            ->orderBy('pay_day')
            ->get()
            ->map(fn ($row) => Carbon::parse($row->pay_day)->toDateString())
            ->first(fn (string $payDay) => ($this->summarize($card, $payDay)['open_total'] ?? 0) > 0);
    }

    public function currentInvoice(Card $card): array
    {
        $payDay = $this->nextOpenInvoicePayDay($card);

        if (is_null($payDay)) {
            return [
                'pay_day' => null,
                'closure_date' => null,
                'total' => 0.0,
                'paid_total' => 0.0,
                'open_total' => 0.0,
                'status' => 'no_invoice',
                'installments_count' => 0,
                'payment_count' => 0,
                'paid_at' => null,
                'payment_transaction_id' => null,
                'invoice' => 0.0,
                'is_paid' => false,
            ];
        }

        return $this->summarize($card, $payDay);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function summariesForCard(Card $card): Collection
    {
        return Installment::query()
            ->where('card_id', $card->id)
            ->select('pay_day')
            ->distinct()
            ->orderBy('pay_day')
            ->get()
            ->map(fn ($row) => $this->summarize($card, Carbon::parse($row->pay_day)->toDateString()))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(Card $card, string $payDay): array
    {
        $normalizedPayDay = Carbon::parse($payDay)->toDateString();

        $installments = Installment::query()
            ->where('card_id', $card->id)
            ->whereDate('pay_day', $normalizedPayDay)
            ->get();

        $invoiceTotal = (float) $installments->sum('installment_value');
        $openInstallmentTotal = (float) $installments->whereNull('paid_at')->sum('installment_value');

        $payments = CardInvoicePayment::query()
            ->where('card_id', $card->id)
            ->whereDate('pay_day', $normalizedPayDay)
            ->orderBy('paid_at')
            ->get();

        $recordedPaidTotal = (float) $payments->sum('amount_paid');
        $legacyFullyPaid = $payments->isEmpty() && $invoiceTotal > 0 && $openInstallmentTotal <= 0;
        $legacyPaidTotal = $legacyFullyPaid ? $invoiceTotal : 0.0;

        $paidTotal = min($invoiceTotal, $recordedPaidTotal + $legacyPaidTotal);
        $openTotal = max($invoiceTotal - $paidTotal, 0.0);
        $closureDate = $invoiceTotal > 0 ? $this->invoiceClosureDate($card, $normalizedPayDay)->toDateString() : null;

        $paidAt = null;
        if ($payments->isNotEmpty()) {
            $lastPaidAt = $payments->pluck('paid_at')->filter()->max();
            $paidAt = $lastPaidAt
                ? Carbon::parse($lastPaidAt)->timezone('America/Sao_Paulo')->format('Y-m-d H:i:s')
                : null;
        } elseif ($legacyFullyPaid) {
            $lastPaidAt = $installments->max('paid_at');
            $paidAt = $lastPaidAt
                ? Carbon::parse($lastPaidAt)->timezone('America/Sao_Paulo')->format('Y-m-d H:i:s')
                : null;
        }

        $paymentTransactionId = null;
        if ($payments->count() === 1 && $openTotal <= 0) {
            $paymentTransactionId = (int) $payments->first()->payment_transaction_id;
        } elseif ($payments->isEmpty() && $legacyFullyPaid) {
            $legacyIds = $installments->pluck('payment_transaction_id')->filter()->unique()->values();
            if ($legacyIds->count() === 1) {
                $paymentTransactionId = (int) $legacyIds->first();
            }
        }

        return [
            'pay_day' => $normalizedPayDay,
            'closure_date' => $closureDate,
            'total' => $invoiceTotal,
            'paid_total' => $paidTotal,
            'open_total' => $openTotal,
            'status' => $this->resolveInvoiceStatus($invoiceTotal, $openTotal, $closureDate, $paidTotal),
            'installments_count' => $installments->count(),
            'payment_count' => $payments->count(),
            'paid_at' => $paidAt,
            'payment_transaction_id' => $paymentTransactionId,
            'invoice' => $openTotal,
            'is_paid' => $invoiceTotal > 0 && $openTotal <= 0,
        ];
    }

    private function resolveInvoiceStatus(float $invoiceTotal, float $openTotal, ?string $closureDate, float $paidTotal): string
    {
        if ($invoiceTotal <= 0) {
            return 'no_invoice';
        }

        if (!is_null($closureDate) && Carbon::today('America/Sao_Paulo')->lt(Carbon::parse($closureDate)->startOfDay())) {
            return 'aguardando_fechamento';
        }

        if ($openTotal <= 0) {
            return 'paga';
        }

        if ($paidTotal > 0) {
            return 'parcialmente_paga';
        }

        return 'disponivel_para_pagamento';
    }

    private function invoiceClosureDate(Card $card, string $invoicePayDay): Carbon
    {
        $payDay = Carbon::parse($invoicePayDay)->startOfDay();
        $closureDay = (int) $card->closure;
        $expirationDay = (int) $card->expiration;

        $closureMonth = $expirationDay > $closureDay
            ? $payDay->copy()
            : $payDay->copy()->subMonth();

        return Carbon::create(
            $closureMonth->year,
            $closureMonth->month,
            min($closureDay, $closureMonth->daysInMonth),
            0,
            0,
            0
        );
    }
}
