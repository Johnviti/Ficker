<?php

namespace App\Services\Analysis;

use App\Models\Card;
use App\Models\CardInvoicePayment;
use App\Models\Spending;
use App\Services\Cards\CardInvoiceSummaryService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalysisService
{
    private const TIMEZONE = 'America/Sao_Paulo';
    private const CURRENCY = 'BRL';

    public function __construct(
        private readonly CardInvoiceSummaryService $cardInvoiceSummaryService
    ) {
    }

    public function resolveFilters(array $filters): array
    {
        $now = Carbon::now(self::TIMEZONE);

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $dateFrom = Carbon::parse($filters['date_from'], self::TIMEZONE)->startOfDay();
            $dateTo = Carbon::parse($filters['date_to'], self::TIMEZONE)->endOfDay();
            $periodMode = 'explicit_range';
            $month = null;
            $year = null;
        } elseif (!empty($filters['month']) && !empty($filters['year'])) {
            $dateFrom = Carbon::create((int) $filters['year'], (int) $filters['month'], 1, 0, 0, 0, self::TIMEZONE)->startOfMonth();
            $dateTo = $dateFrom->copy()->endOfMonth();
            $periodMode = 'month';
            $month = (int) $filters['month'];
            $year = (int) $filters['year'];
        } else {
            $dateFrom = $now->copy()->startOfMonth();
            $dateTo = $now->copy()->endOfMonth();
            $periodMode = 'default_current_month';
            $month = (int) $dateFrom->month;
            $year = (int) $dateFrom->year;
        }

        return [
            'period_mode' => $periodMode,
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'month' => $month,
            'year' => $year,
            'group_by' => $filters['group_by'] ?? 'month',
            'card_id' => isset($filters['card_id']) ? (int) $filters['card_id'] : null,
            'category_id' => isset($filters['category_id']) ? (int) $filters['category_id'] : null,
            'type_id' => isset($filters['type_id']) ? (int) $filters['type_id'] : null,
        ];
    }

    public function buildSummary(int $userId, array $filters): array
    {
        $row = $this->baseTransactionsQuery($userId, $filters)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN t.type_id = 1 THEN t.transaction_value ELSE 0 END), 0) as income_total,
                COALESCE(SUM(CASE WHEN t.type_id = 2 AND (t.payment_method_id IS NULL OR t.payment_method_id != 4) THEN t.transaction_value ELSE 0 END), 0) as real_spending_total,
                COALESCE(SUM(CASE WHEN t.type_id = 2 AND t.payment_method_id = 4 THEN t.transaction_value ELSE 0 END), 0) as credit_card_purchase_total,
                COALESCE(SUM(CASE WHEN t.type_id = 2 AND (c.category_description = ? OR t.transaction_description LIKE ?) THEN t.transaction_value ELSE 0 END), 0) as invoice_payment_total,
                COUNT(*) as total_transactions_count,
                COALESCE(SUM(CASE WHEN t.type_id = 1 THEN 1 ELSE 0 END), 0) as income_transactions_count,
                COALESCE(SUM(CASE WHEN t.type_id = 2 THEN 1 ELSE 0 END), 0) as expense_transactions_count
            ', ['Pagamento de fatura', 'Pagamento fatura - %'])
            ->first();

        $plannedSpendingTotal = $this->plannedSpendingTotal($userId, $filters['date_from'], $filters['date_to']);

        $incomeTotal = (float) ($row->income_total ?? 0);
        $realSpendingTotal = (float) ($row->real_spending_total ?? 0);

        return [
            'period_start' => $filters['date_from'],
            'period_end' => $filters['date_to'],
            'income_total' => $incomeTotal,
            'real_spending_total' => $realSpendingTotal,
            'credit_card_purchase_total' => (float) ($row->credit_card_purchase_total ?? 0),
            'invoice_payment_total' => (float) ($row->invoice_payment_total ?? 0),
            'planned_spending_total' => $plannedSpendingTotal,
            'balance_delta' => $incomeTotal - $realSpendingTotal,
            'planned_spending_difference' => $plannedSpendingTotal - $realSpendingTotal,
            'total_transactions_count' => (int) ($row->total_transactions_count ?? 0),
            'income_transactions_count' => (int) ($row->income_transactions_count ?? 0),
            'expense_transactions_count' => (int) ($row->expense_transactions_count ?? 0),
        ];
    }

    public function buildTimeline(int $userId, array $filters): array
    {
        $groupBy = $filters['group_by'];

        $rows = $this->baseTransactionsQuery($userId, $filters)
            ->selectRaw($this->timelinePeriodSelect($groupBy))
            ->selectRaw('
                COALESCE(SUM(CASE WHEN t.type_id = 1 THEN t.transaction_value ELSE 0 END), 0) as income_total,
                COALESCE(SUM(CASE WHEN t.type_id = 2 AND (t.payment_method_id IS NULL OR t.payment_method_id != 4) THEN t.transaction_value ELSE 0 END), 0) as real_spending_total,
                COALESCE(SUM(CASE WHEN t.type_id = 2 AND t.payment_method_id = 4 THEN t.transaction_value ELSE 0 END), 0) as credit_card_purchase_total,
                COALESCE(SUM(CASE WHEN t.type_id = 2 AND (c.category_description = ? OR t.transaction_description LIKE ?) THEN t.transaction_value ELSE 0 END), 0) as invoice_payment_total
            ', ['Pagamento de fatura', 'Pagamento fatura - %'])
            ->groupBy('period_key', 'period_start', 'period_end')
            ->orderBy('period_start')
            ->get();

        $plannedSpendingsByMonth = $this->plannedSpendingByMonth($userId, $filters['date_from'], $filters['date_to']);

        return $rows->map(function ($row) use ($groupBy, $plannedSpendingsByMonth) {
            $plannedSpendingTotal = null;

            if ($groupBy === 'month') {
                $plannedSpendingTotal = (float) ($plannedSpendingsByMonth[$row->period_key] ?? 0);
            }

            $incomeTotal = (float) ($row->income_total ?? 0);
            $realSpendingTotal = (float) ($row->real_spending_total ?? 0);

            return [
                'period_key' => $row->period_key,
                'period_start' => $row->period_start,
                'period_end' => $row->period_end,
                'income_total' => $incomeTotal,
                'real_spending_total' => $realSpendingTotal,
                'credit_card_purchase_total' => (float) ($row->credit_card_purchase_total ?? 0),
                'invoice_payment_total' => (float) ($row->invoice_payment_total ?? 0),
                'planned_spending_total' => $plannedSpendingTotal,
                'balance_delta' => $incomeTotal - $realSpendingTotal,
                'planned_spending_difference' => is_null($plannedSpendingTotal)
                    ? null
                    : $plannedSpendingTotal - $realSpendingTotal,
            ];
        })->values()->all();
    }

    public function buildMeta(array $filters): array
    {
        return [
            'currency' => self::CURRENCY,
            'timezone' => self::TIMEZONE,
            'period_mode' => $filters['period_mode'],
        ];
    }

    public function buildCards(int $userId, array $filters): array
    {
        $cards = Card::query()
            ->with('flag')
            ->where('user_id', $userId)
            ->when($filters['card_id'], function ($query, $cardId) {
                $query->where('id', $cardId);
            })
            ->orderBy('card_description')
            ->get();

        return $cards->map(function (Card $card) use ($filters) {
            $invoiceSummaries = $this->cardInvoiceSummaryService->summariesForCard($card);
            $invoiceSummariesCollection = collect($invoiceSummaries);
            $currentInvoice = $invoiceSummariesCollection->first(fn (array $summary) => ($summary['open_total'] ?? 0) > 0);
            $currentInvoicePayDay = $currentInvoice['pay_day'] ?? null;
            $nextInvoice = $invoiceSummariesCollection->first(function (array $summary) use ($currentInvoicePayDay) {
                $summaryPayDay = $summary['pay_day'] ?? null;

                if (empty($summaryPayDay) || (float) ($summary['total'] ?? 0) <= 0) {
                    return false;
                }

                if (is_null($currentInvoicePayDay)) {
                    return true;
                }

                return $summaryPayDay > $currentInvoicePayDay;
            });

            $currentInvoiceClosureDate = isset($currentInvoice['closure_date'])
                ? Carbon::parse($currentInvoice['closure_date'], self::TIMEZONE)
                : null;
            $currentInvoiceOriginalTotal = (float) ($currentInvoice['total'] ?? 0);
            $currentInvoicePaidTotal = (float) ($currentInvoice['paid_total'] ?? 0);
            $currentInvoiceTotal = (float) ($currentInvoice['open_total'] ?? 0);
            $openInvoiceTotal = (float) $invoiceSummariesCollection->sum('open_total');
            $futureCommitmentTotal = max($openInvoiceTotal - $currentInvoiceTotal, 0);
            $invoicesInPeriod = $invoiceSummariesCollection->filter(function (array $summary) use ($filters) {
                $payDay = $summary['pay_day'] ?? null;

                return !is_null($payDay)
                    && $payDay >= $filters['date_from']
                    && $payDay <= $filters['date_to'];
            });
            $invoicesDueTotalInPeriod = (float) $invoicesInPeriod->sum('total');
            $invoicesSettledTotalInPeriod = (float) $invoicesInPeriod->sum(function (array $summary) {
                return min(
                    (float) ($summary['paid_total'] ?? 0),
                    (float) ($summary['total'] ?? 0)
                );
            });

            $purchasesTotalInPeriod = (float) DB::table('transactions')
                ->where('user_id', $card->user_id)
                ->where('card_id', $card->id)
                ->whereBetween('date', [$filters['date_from'], $filters['date_to']])
                ->where('type_id', 2)
                ->where('payment_method_id', 4)
                ->sum('transaction_value');

            $purchasesCountInPeriod = (int) DB::table('transactions')
                ->where('user_id', $card->user_id)
                ->where('card_id', $card->id)
                ->whereBetween('date', [$filters['date_from'], $filters['date_to']])
                ->where('type_id', 2)
                ->where('payment_method_id', 4)
                ->count();

            $largestPurchaseInPeriod = DB::table('transactions')
                ->where('user_id', $card->user_id)
                ->where('card_id', $card->id)
                ->whereBetween('date', [$filters['date_from'], $filters['date_to']])
                ->where('type_id', 2)
                ->where('payment_method_id', 4)
                ->orderByDesc('transaction_value')
                ->orderByDesc('date')
                ->select('transaction_value', 'date', 'transaction_description')
                ->first();

            $latestPurchaseInPeriod = DB::table('transactions')
                ->where('user_id', $card->user_id)
                ->where('card_id', $card->id)
                ->whereBetween('date', [$filters['date_from'], $filters['date_to']])
                ->where('type_id', 2)
                ->where('payment_method_id', 4)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->select('transaction_value', 'date', 'transaction_description')
                ->first();

            $invoicePaymentsTotalInPeriod = (float) CardInvoicePayment::query()
                ->join('transactions as payment', 'payment.id', '=', 'card_invoice_payments.payment_transaction_id')
                ->where('card_invoice_payments.card_id', $card->id)
                ->whereBetween('payment.date', [$filters['date_from'], $filters['date_to']])
                ->sum('card_invoice_payments.amount_paid');

            $legacyInvoicePaymentsTotalInPeriod = (float) DB::table('installments as i')
                ->join('transactions as payment', 'payment.id', '=', 'i.payment_transaction_id')
                ->where('i.card_id', $card->id)
                ->whereNotNull('i.payment_transaction_id')
                ->whereBetween('payment.date', [$filters['date_from'], $filters['date_to']])
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('card_invoice_payments as cip')
                        ->whereColumn('cip.card_id', 'i.card_id')
                        ->whereColumn('cip.pay_day', 'i.pay_day');
                })
                ->sum('i.installment_value');

            $paidInvoicesCountInPeriod = collect($invoiceSummaries)
                ->filter(fn (array $summary) => ($summary['is_paid'] ?? false) === true)
                ->filter(function (array $summary) use ($filters) {
                    if (empty($summary['paid_at'])) {
                        return false;
                    }

                    $paidAt = Carbon::parse($summary['paid_at'], self::TIMEZONE)->toDateString();

                    return $paidAt >= $filters['date_from'] && $paidAt <= $filters['date_to'];
                })
                ->count();

            return [
                'card_id' => $card->id,
                'card_description' => $card->card_description,
                'flag_description' => $card->flag?->flag_description,
                'closure_day' => (int) $card->closure,
                'expiration_day' => (int) $card->expiration,
                'current_invoice_pay_day' => is_null($currentInvoicePayDay)
                    ? null
                    : Carbon::parse($currentInvoicePayDay, self::TIMEZONE)->toDateString(),
                'current_invoice_closure_date' => $currentInvoiceClosureDate?->toDateString(),
                'current_invoice_original_total' => $currentInvoiceOriginalTotal,
                'current_invoice_paid_total' => $currentInvoicePaidTotal,
                'next_invoice_pay_day' => isset($nextInvoice['pay_day'])
                    ? Carbon::parse($nextInvoice['pay_day'], self::TIMEZONE)->toDateString()
                    : null,
                'next_invoice_total' => (float) ($nextInvoice['total'] ?? 0),
                'current_invoice_total' => $currentInvoiceTotal,
                'open_invoice_total' => $openInvoiceTotal,
                'future_commitment_total' => $futureCommitmentTotal,
                'can_pay_current_invoice' => !is_null($currentInvoicePayDay)
                    && $currentInvoiceTotal > 0
                    && !is_null($currentInvoiceClosureDate)
                    && Carbon::today(self::TIMEZONE)->startOfDay()->gte($currentInvoiceClosureDate->copy()->startOfDay()),
                'current_invoice_status' => $currentInvoice['status'] ?? 'no_invoice',
                'purchases_total_in_period' => $purchasesTotalInPeriod,
                'purchases_count_in_period' => $purchasesCountInPeriod,
                'average_purchase_in_period' => $purchasesCountInPeriod > 0
                    ? round($purchasesTotalInPeriod / $purchasesCountInPeriod, 2)
                    : 0.0,
                'largest_purchase_in_period' => (float) ($largestPurchaseInPeriod->transaction_value ?? 0),
                'largest_purchase_date_in_period' => $largestPurchaseInPeriod->date ?? null,
                'largest_purchase_description_in_period' => $largestPurchaseInPeriod->transaction_description ?? null,
                'latest_purchase_in_period' => (float) ($latestPurchaseInPeriod->transaction_value ?? 0),
                'latest_purchase_date_in_period' => $latestPurchaseInPeriod->date ?? null,
                'latest_purchase_description_in_period' => $latestPurchaseInPeriod->transaction_description ?? null,
                'invoices_due_total_in_period' => $invoicesDueTotalInPeriod,
                'invoices_settled_total_in_period' => $invoicesSettledTotalInPeriod,
                'invoice_payments_total_in_period' => $invoicePaymentsTotalInPeriod + $legacyInvoicePaymentsTotalInPeriod,
                'paid_invoices_count_in_period' => (int) ($paidInvoicesCountInPeriod ?? 0),
            ];
        })->values()->all();
    }

    public function buildInvoices(int $userId, array $filters): array
    {
        $cards = Card::query()
            ->with('flag')
            ->where('user_id', $userId)
            ->get()
            ->keyBy('id');

        return $cards
            ->flatMap(function (Card $card) use ($filters) {
                return $this->cardInvoiceSummaryService
                    ->summariesForCard($card)
                    ->filter(function (array $summary) use ($filters) {
                        $payDay = $summary['pay_day'] ?? null;

                        return !is_null($payDay)
                            && $payDay >= $filters['date_from']
                            && $payDay <= $filters['date_to'];
                    })
                    ->map(function (array $summary) use ($card) {
                        return [
                            'card_id' => $card->id,
                            'card_description' => $card->card_description,
                            'flag_description' => $card->flag?->flag_description,
                            'pay_day' => $summary['pay_day'],
                            'closure_date' => $summary['closure_date'],
                            'invoice_total' => $summary['total'],
                            'paid_total' => $summary['paid_total'],
                            'open_total' => $summary['open_total'],
                            'is_paid' => $summary['is_paid'],
                            'paid_at' => $summary['paid_at'],
                            'payment_transaction_id' => $summary['payment_transaction_id'],
                            'installments_count' => $summary['installments_count'],
                            'status' => $summary['status'],
                        ];
                    });
            })
            ->sortBy(fn (array $item) => ($item['pay_day'] ?? '') . '|' . str_pad((string) ($item['card_id'] ?? 0), 10, '0', STR_PAD_LEFT))
            ->values()
            ->all();
    }

    public function buildCategories(int $userId, array $filters): array
    {
        $rows = $this->baseTransactionsQuery($userId, $filters)
            ->selectRaw('
                COALESCE(t.category_id, 0) as category_id,
                COALESCE(c.category_description, ?) as category_description,
                COALESCE(SUM(CASE WHEN t.type_id = 1 THEN t.transaction_value ELSE 0 END), 0) as income_total,
                COALESCE(SUM(CASE WHEN t.type_id = 2 AND (t.payment_method_id IS NULL OR t.payment_method_id != 4) THEN t.transaction_value ELSE 0 END), 0) as real_spending_total,
                COALESCE(SUM(CASE WHEN t.type_id = 2 AND t.payment_method_id = 4 THEN t.transaction_value ELSE 0 END), 0) as credit_card_purchase_total,
                COALESCE(SUM(CASE WHEN t.type_id = 2 AND (c.category_description = ? OR t.transaction_description LIKE ?) THEN t.transaction_value ELSE 0 END), 0) as invoice_payment_total,
                COUNT(*) as total_transactions_count
            ', ['Sem categoria', 'Pagamento de fatura', 'Pagamento fatura - %'])
            ->groupBy('t.category_id', 'c.category_description')
            ->orderByDesc(DB::raw('COALESCE(SUM(CASE WHEN t.type_id = 2 THEN t.transaction_value ELSE 0 END), 0)'))
            ->get();

        $totals = [
            'income_total' => (float) $rows->sum('income_total'),
            'real_spending_total' => (float) $rows->sum('real_spending_total'),
            'credit_card_purchase_total' => (float) $rows->sum('credit_card_purchase_total'),
            'invoice_payment_total' => (float) $rows->sum('invoice_payment_total'),
        ];

        return $rows->map(function ($row) use ($totals) {
            $incomeTotal = (float) ($row->income_total ?? 0);
            $realSpendingTotal = (float) ($row->real_spending_total ?? 0);
            $creditCardPurchaseTotal = (float) ($row->credit_card_purchase_total ?? 0);
            $invoicePaymentTotal = (float) ($row->invoice_payment_total ?? 0);
            $expenseCompositionTotal = $realSpendingTotal + $creditCardPurchaseTotal;
            $overallExpenseBase = $totals['real_spending_total'] + $totals['credit_card_purchase_total'];
            $purchaseCompositionTotal = max($realSpendingTotal - $invoicePaymentTotal, 0) + $creditCardPurchaseTotal;
            $overallPurchaseBase = max($overallExpenseBase - $totals['invoice_payment_total'], 0);

            return [
                'category_id' => (int) ($row->category_id ?? 0),
                'category_description' => $row->category_description,
                'income_total' => $incomeTotal,
                'real_spending_total' => $realSpendingTotal,
                'credit_card_purchase_total' => $creditCardPurchaseTotal,
                'invoice_payment_total' => $invoicePaymentTotal,
                'expense_composition_total' => $expenseCompositionTotal,
                'purchase_composition_total' => $purchaseCompositionTotal,
                'total_transactions_count' => (int) ($row->total_transactions_count ?? 0),
                'expense_composition_percentage' => $overallExpenseBase > 0
                    ? round(($expenseCompositionTotal / $overallExpenseBase) * 100, 2)
                    : 0.0,
                'purchase_composition_percentage' => $overallPurchaseBase > 0
                    ? round(($purchaseCompositionTotal / $overallPurchaseBase) * 100, 2)
                    : 0.0,
            ];
        })->values()->all();
    }

    public function buildComposition(int $userId, array $filters): array
    {
        $summary = $this->buildSummary($userId, $filters);

        $incomeTotal = (float) ($summary['income_total'] ?? 0);
        $realSpendingTotal = (float) ($summary['real_spending_total'] ?? 0);
        $creditCardPurchaseTotal = (float) ($summary['credit_card_purchase_total'] ?? 0);
        $invoicePaymentTotal = (float) ($summary['invoice_payment_total'] ?? 0);
        $plannedSpendingTotal = (float) ($summary['planned_spending_total'] ?? 0);
        $financialOutflowTotal = $realSpendingTotal + $creditCardPurchaseTotal;

        return [
            'period_start' => $summary['period_start'],
            'period_end' => $summary['period_end'],
            'income_total' => $incomeTotal,
            'real_spending_total' => $realSpendingTotal,
            'credit_card_purchase_total' => $creditCardPurchaseTotal,
            'invoice_payment_total' => $invoicePaymentTotal,
            'planned_spending_total' => $plannedSpendingTotal,
            'balance_delta' => (float) ($summary['balance_delta'] ?? 0),
            'planned_spending_difference' => (float) ($summary['planned_spending_difference'] ?? 0),
            'financial_outflow_total' => $financialOutflowTotal,
            'composition_percentages' => [
                'real_spending' => $financialOutflowTotal > 0
                    ? round(($realSpendingTotal / $financialOutflowTotal) * 100, 2)
                    : 0.0,
                'credit_card_purchases' => $financialOutflowTotal > 0
                    ? round(($creditCardPurchaseTotal / $financialOutflowTotal) * 100, 2)
                    : 0.0,
                'invoice_payments_within_real_spending' => $realSpendingTotal > 0
                    ? round(($invoicePaymentTotal / $realSpendingTotal) * 100, 2)
                    : 0.0,
                'real_spending_vs_planned' => $plannedSpendingTotal > 0
                    ? round(($realSpendingTotal / $plannedSpendingTotal) * 100, 2)
                    : 0.0,
            ],
        ];
    }

    public function buildPaymentMethods(int $userId, array $filters): array
    {
        $rows = DB::table('transactions as t')
            ->leftJoin('categories as c', 'c.id', '=', 't.category_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 't.payment_method_id')
            ->where('t.user_id', $userId)
            ->whereBetween('t.date', [$filters['date_from'], $filters['date_to']])
            ->where('t.type_id', 2)
            ->where(function ($query) {
                $this->applyNonInvoicePaymentFilter($query);
            })
            ->when($filters['card_id'], function ($query, $cardId) {
                $query->where('t.card_id', $cardId);
            })
            ->when($filters['category_id'], function ($query, $categoryId) {
                $query->where('t.category_id', $categoryId);
            })
            ->selectRaw('
                COALESCE(t.payment_method_id, 0) as payment_method_id,
                COALESCE(pm.payment_method_description, ?) as payment_method_description,
                COALESCE(SUM(t.transaction_value), 0) as total_value,
                COUNT(*) as total_transactions_count
            ', ['Não informado'])
            ->groupBy('t.payment_method_id', 'pm.payment_method_description')
            ->orderByDesc('total_value')
            ->get();

        return $this->mapPaymentMethodRows($rows);
    }

    public function buildInvoicePaymentMethods(int $userId, array $filters): array
    {
        $rows = DB::table('transactions as t')
            ->leftJoin('categories as c', 'c.id', '=', 't.category_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 't.payment_method_id')
            ->where('t.user_id', $userId)
            ->whereBetween('t.date', [$filters['date_from'], $filters['date_to']])
            ->where('t.type_id', 2)
            ->where(function ($query) {
                $this->applyInvoicePaymentFilter($query);
            })
            ->when($filters['card_id'], function ($query, $cardId) {
                $query->where('t.card_id', $cardId);
            })
            ->when($filters['category_id'], function ($query, $categoryId) {
                $query->where('t.category_id', $categoryId);
            })
            ->selectRaw('
                COALESCE(t.payment_method_id, 0) as payment_method_id,
                COALESCE(pm.payment_method_description, ?) as payment_method_description,
                COALESCE(SUM(t.transaction_value), 0) as total_value,
                COUNT(*) as total_transactions_count
            ', ['NÃ£o informado'])
            ->groupBy('t.payment_method_id', 'pm.payment_method_description')
            ->orderByDesc('total_value')
            ->get();

        return $this->mapPaymentMethodRows($rows);
    }

    private function mapPaymentMethodRows(Collection $rows): array
    {
        $overallTotal = (float) $rows->sum('total_value');

        return $rows->map(function ($row) use ($overallTotal) {
            $totalValue = (float) ($row->total_value ?? 0);

            return [
                'payment_method_id' => (int) ($row->payment_method_id ?? 0),
                'payment_method_description' => $row->payment_method_description,
                'total_value' => $totalValue,
                'total_transactions_count' => (int) ($row->total_transactions_count ?? 0),
                'percentage' => $overallTotal > 0
                    ? round(($totalValue / $overallTotal) * 100, 2)
                    : 0.0,
            ];
        })->values()->all();
    }

    private function applyInvoicePaymentFilter($query): void
    {
        $query->where(function ($nestedQuery) {
            $nestedQuery
                ->where('c.category_description', 'Pagamento de fatura')
                ->orWhere('t.transaction_description', 'like', 'Pagamento fatura - %');
        });
    }

    private function applyNonInvoicePaymentFilter($query): void
    {
        $query->where(function ($nestedQuery) {
            $nestedQuery
                ->whereNull('c.category_description')
                ->orWhere('c.category_description', '!=', 'Pagamento de fatura');
        })->where('t.transaction_description', 'not like', 'Pagamento fatura - %');
    }

    public function buildTopExpenses(int $userId, array $filters, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));

        $topTransactions = $this->baseTransactionsQuery($userId, $filters)
            ->leftJoin('cards as card', 'card.id', '=', 't.card_id')
            ->selectRaw('
                t.id,
                t.transaction_description,
                t.transaction_value,
                t.date,
                t.type_id,
                t.payment_method_id,
                COALESCE(c.category_description, ?) as category_description,
                card.card_description
            ', ['Sem categoria'])
            ->where('t.type_id', 2)
            ->orderByDesc('t.transaction_value')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $isCreditCardPurchase = (int) $row->payment_method_id === 4;
                $isInvoicePayment = $row->category_description === 'Pagamento de fatura'
                    || str_starts_with((string) $row->transaction_description, 'Pagamento fatura - ');

                return [
                    'transaction_id' => (int) $row->id,
                    'transaction_description' => $row->transaction_description,
                    'transaction_value' => (float) $row->transaction_value,
                    'date' => $row->date,
                    'category_description' => $row->category_description,
                    'card_description' => $row->card_description,
                    'is_credit_card_purchase' => $isCreditCardPurchase,
                    'is_invoice_payment' => $isInvoicePayment,
                    'affects_real_spending' => !$isCreditCardPurchase,
                ];
            })
            ->values()
            ->all();

        $topCategories = $this->baseTransactionsQuery($userId, $filters)
            ->selectRaw('
                COALESCE(t.category_id, 0) as category_id,
                COALESCE(c.category_description, ?) as category_description,
                COALESCE(SUM(CASE WHEN t.type_id = 2 THEN t.transaction_value ELSE 0 END), 0) as total_value,
                COUNT(*) as total_transactions_count
            ', ['Sem categoria'])
            ->where('t.type_id', 2)
            ->groupBy('t.category_id', 'c.category_description')
            ->orderByDesc('total_value')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                return [
                    'category_id' => (int) ($row->category_id ?? 0),
                    'category_description' => $row->category_description,
                    'total_value' => (float) $row->total_value,
                    'total_transactions_count' => (int) $row->total_transactions_count,
                ];
            })
            ->values()
            ->all();

        $topCards = $this->baseTransactionsQuery($userId, $filters)
            ->leftJoin('cards as card', 'card.id', '=', 't.card_id')
            ->selectRaw('
                t.card_id,
                card.card_description,
                COALESCE(SUM(CASE WHEN t.type_id = 2 AND t.payment_method_id = 4 THEN t.transaction_value ELSE 0 END), 0) as credit_card_purchase_total,
                COALESCE(SUM(CASE WHEN t.type_id = 2 AND (t.payment_method_id IS NULL OR t.payment_method_id != 4) THEN t.transaction_value ELSE 0 END), 0) as real_spending_total,
                COUNT(*) as total_transactions_count
            ')
            ->whereNotNull('t.card_id')
            ->groupBy('t.card_id', 'card.card_description')
            ->orderByDesc(DB::raw('COALESCE(SUM(CASE WHEN t.type_id = 2 THEN t.transaction_value ELSE 0 END), 0)'))
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                return [
                    'card_id' => (int) $row->card_id,
                    'card_description' => $row->card_description,
                    'credit_card_purchase_total' => (float) $row->credit_card_purchase_total,
                    'real_spending_total' => (float) $row->real_spending_total,
                    'total_transactions_count' => (int) $row->total_transactions_count,
                ];
            })
            ->values()
            ->all();

        return [
            'limit' => $limit,
            'transactions' => $topTransactions,
            'categories' => $topCategories,
            'cards' => $topCards,
        ];
    }

    private function baseTransactionsQuery(int $userId, array $filters)
    {
        return DB::table('transactions as t')
            ->leftJoin('categories as c', 'c.id', '=', 't.category_id')
            ->where('t.user_id', $userId)
            ->whereBetween('t.date', [$filters['date_from'], $filters['date_to']])
            ->when($filters['card_id'], function ($query, $cardId) {
                $query->where('t.card_id', $cardId);
            })
            ->when($filters['category_id'], function ($query, $categoryId) {
                $query->where('t.category_id', $categoryId);
            })
            ->when($filters['type_id'], function ($query, $typeId) {
                $query->where('t.type_id', $typeId);
            });
    }

    private function timelinePeriodSelect(string $groupBy): string
    {
        if ($groupBy === 'day') {
            return "
                DATE_FORMAT(t.date, '%Y-%m-%d') as period_key,
                DATE(t.date) as period_start,
                DATE(t.date) as period_end
            ";
        }

        return "
            DATE_FORMAT(t.date, '%Y-%m') as period_key,
            DATE_FORMAT(t.date, '%Y-%m-01') as period_start,
            LAST_DAY(t.date) as period_end
        ";
    }

    private function plannedSpendingTotal(int $userId, string $dateFrom, string $dateTo): float
    {
        return array_sum($this->plannedSpendingByMonth($userId, $dateFrom, $dateTo));
    }

    private function plannedSpendingByMonth(int $userId, string $dateFrom, string $dateTo): array
    {
        $start = Carbon::parse($dateFrom, self::TIMEZONE)->startOfMonth();
        $end = Carbon::parse($dateTo, self::TIMEZONE)->endOfMonth();

        /** @var Collection<int, Spending> $spendings */
        $spendings = Spending::where('user_id', $userId)
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->orderBy('created_at')
            ->get();

        return $spendings
            ->groupBy(function (Spending $spending) {
                return Carbon::parse($spending->created_at, self::TIMEZONE)->format('Y-m');
            })
            ->map(function (Collection $items) {
                return (float) optional($items->last())->planned_spending;
            })
            ->all();
    }

    private function resolveInvoiceStatus(float $openTotal, ?string $closureDate): string
    {
        if ($openTotal <= 0) {
            return 'paid';
        }

        if (is_null($closureDate)) {
            return 'no_invoice';
        }

        return Carbon::today(self::TIMEZONE)->startOfDay()->gte(Carbon::parse($closureDate, self::TIMEZONE)->startOfDay())
            ? 'payable'
            : 'awaiting_closure';
    }
}
