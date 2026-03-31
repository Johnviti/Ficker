<?php

namespace App\Services\Cards;

use App\Exceptions\CardInvoicePaymentException;
use App\Models\Card;
use App\Models\Category;
use App\Models\CardInvoicePayment;
use App\Models\Installment;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CardInvoicePaymentService
{
    public function __construct(
        private readonly CardInvoiceSummaryService $cardInvoiceSummaryService
    ) {
    }

    /**
     * @throws ValidationException
     * @throws CardInvoicePaymentException
     */
    public function payNextInvoice(int $userId, int $cardId, array $payload): array
    {
        $card = $this->resolveCard($userId, $cardId);
        $nextPayDay = $this->cardInvoiceSummaryService->nextOpenInvoicePayDay($card);

        if (!$nextPayDay) {
            throw new CardInvoicePaymentException('Nao ha fatura em aberto para este cartao.', 422);
        }

        return $this->payInvoiceByPayDay($userId, $cardId, $nextPayDay, $payload);
    }

    /**
     * @throws ValidationException
     * @throws CardInvoicePaymentException
     */
    public function payInvoiceByPayDay(int $userId, int $cardId, string $payDay, array $payload): array
    {
        $card = $this->resolveCard($userId, $cardId);
        $validated = $this->validate($payload);
        $invoicePayDay = Carbon::parse($payDay)->toDateString();
        $invoiceSummary = $this->cardInvoiceSummaryService->summarize($card, $invoicePayDay);
        $openTotal = (float) ($invoiceSummary['open_total'] ?? 0);

        if ($openTotal <= 0) {
            throw new CardInvoicePaymentException(
                'Esta fatura nao possui parcelas em aberto (ja paga ou inexistente).',
                422,
                ['invoice_pay_day' => [$invoicePayDay]]
            );
        }

        $closureDate = $this->invoiceClosureDate($card, $invoicePayDay);
        $today = Carbon::today()->startOfDay();

        if ($today->lt($closureDate)) {
            throw new CardInvoicePaymentException(
                'A fatura ainda nao fechou. Voce so pode pagar apos o fechamento.',
                422,
                [
                    'invoice_pay_day' => [$invoicePayDay],
                    'invoice_closure_date' => [$closureDate->toDateString()],
                ]
            );
        }

        $category = $this->resolveCategory($userId, $validated);
        $paymentDate = $validated['date'] ?? Carbon::today()->toDateString();
        $amountPaid = isset($validated['amount_paid'])
            ? round((float) $validated['amount_paid'], 2)
            : $openTotal;

        if ($amountPaid <= 0 || $amountPaid > $openTotal) {
            throw new CardInvoicePaymentException(
                'O valor pago deve ser maior que zero e menor ou igual ao saldo em aberto da fatura.',
                422,
                ['amount_paid' => ['O valor pago deve ser maior que zero e menor ou igual ao saldo em aberto da fatura.']]
            );
        }

        [$paymentTransaction, $updatedSummary] = DB::transaction(function () use (
            $userId,
            $card,
            $category,
            $amountPaid,
            $paymentDate,
            $validated,
            $invoicePayDay,
            $openTotal
        ) {
            $paymentTransaction = Transaction::create([
                'user_id' => $userId,
                'category_id' => $category->id,
                'type_id' => 2,
                'payment_method_id' => (int) $validated['payment_method_id'],
                'transaction_description' => 'Pagamento fatura - ' . $card->card_description,
                'date' => $paymentDate,
                'transaction_value' => $amountPaid,
            ]);

            CardInvoicePayment::create([
                'card_id' => $card->id,
                'pay_day' => $invoicePayDay,
                'payment_transaction_id' => $paymentTransaction->id,
                'payment_method_id' => (int) $validated['payment_method_id'],
                'category_id' => $category->id,
                'amount_paid' => $amountPaid,
                'paid_at' => now(),
            ]);

            $updatedSummary = $this->cardInvoiceSummaryService->summarize($card, $invoicePayDay);

            if (($updatedSummary['open_total'] ?? 0) <= 0) {
                $hasSingleFullPayment = abs($amountPaid - $openTotal) < 0.00001;

                Installment::query()
                    ->where('card_id', $card->id)
                    ->whereDate('pay_day', $invoicePayDay)
                    ->update([
                        'paid_at' => now(),
                        'payment_transaction_id' => $hasSingleFullPayment ? $paymentTransaction->id : null,
                    ]);

                $updatedSummary = $this->cardInvoiceSummaryService->summarize($card, $invoicePayDay);
            }

            return [$paymentTransaction, $updatedSummary];
        });

        return [
            'status' => 'created',
            'card' => $card,
            'pay_day' => $invoicePayDay,
            'invoice_value' => $amountPaid,
            'amount_paid' => $amountPaid,
            'invoice_total' => (float) ($updatedSummary['total'] ?? 0),
            'paid_total' => (float) ($updatedSummary['paid_total'] ?? 0),
            'open_total' => (float) ($updatedSummary['open_total'] ?? 0),
            'invoice_status' => $updatedSummary['status'] ?? 'no_invoice',
            'category' => $category,
            'payment_transaction' => $paymentTransaction,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function validate(array $payload): array
    {
        return Validator::make($payload, [
            'payment_method_id' => ['required', 'integer', 'not_in:4'],
            'category_id' => ['nullable', 'integer', 'min:0'],
            'category_description' => ['required_if:category_id,0', 'string', 'max:50'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'amount_paid' => ['nullable', 'numeric', 'gt:0'],
        ], [
            'payment_method_id.not_in' => 'Pagamento de fatura nao pode ser feito com metodo cartao de credito.',
        ])->validate();
    }

    /**
     * @throws CardInvoicePaymentException
     */
    private function resolveCard(int $userId, int $cardId): Card
    {
        $card = Card::query()
            ->where('user_id', $userId)
            ->find($cardId);

        if (!$card) {
            throw new CardInvoicePaymentException('Cartao nao encontrado.', 404);
        }

        return $card;
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

    /**
     * @throws CardInvoicePaymentException
     */
    private function resolveCategory(int $userId, array $validated): Category
    {
        $categoryId = $validated['category_id'] ?? null;

        if (is_null($categoryId)) {
            $existing = Category::query()
                ->where('user_id', $userId)
                ->where('type_id', 2)
                ->where('category_description', 'Pagamento de fatura')
                ->first();

            if ($existing) {
                return $existing;
            }

            return Category::create([
                'user_id' => $userId,
                'type_id' => 2,
                'category_description' => 'Pagamento de fatura',
            ]);
        }

        if ((int) $categoryId === 0) {
            return Category::create([
                'user_id' => $userId,
                'type_id' => 2,
                'category_description' => trim((string) ($validated['category_description'] ?? '')),
            ]);
        }

        $category = Category::query()
            ->where('user_id', $userId)
            ->find($categoryId);

        if (!$category) {
            throw new CardInvoicePaymentException('Categoria nao encontrada.', 404, [
                'category_id' => ['Categoria nao encontrada.'],
            ]);
        }

        if ((int) $category->type_id !== 2) {
            throw new CardInvoicePaymentException('A categoria selecionada nao corresponde ao tipo da transacao.', 422, [
                'category_id' => ['A categoria selecionada nao corresponde ao tipo da transacao.'],
            ]);
        }

        return $category;
    }
}
