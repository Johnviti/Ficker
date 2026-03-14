<?php

namespace App\Services\Transactions;

use App\Exceptions\TransactionCreationException;
use App\Http\Controllers\LevelController;
use App\Models\Card;
use App\Models\Category;
use App\Models\Installment;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TransactionCreationService
{
    public function create(int $userId, array $payload): array
    {
        $validated = $this->validate($payload);

        $card = $this->resolveCard($userId, $validated);
        $category = $this->resolveCategory($userId, $validated);

        return DB::transaction(function () use ($userId, $validated, $card, $category) {
            if (is_null($validated['installments'] ?? null)) {
                $transaction = Transaction::create([
                    'user_id' => $userId,
                    'category_id' => $category->id,
                    'type_id' => $validated['type_id'],
                    'payment_method_id' => $validated['payment_method_id'] ?? null,
                    'transaction_description' => $validated['transaction_description'],
                    'date' => $validated['date'],
                    'transaction_value' => $validated['transaction_value'],
                ]);

                LevelController::completeMission((int) $validated['type_id']);

                return [
                    'status' => 'created',
                    'transaction' => $transaction,
                    'installments' => [],
                    'category' => $category,
                ];
            }

            $transaction = Transaction::create([
                'user_id' => $userId,
                'category_id' => $category->id,
                'type_id' => $validated['type_id'],
                'payment_method_id' => $validated['payment_method_id'],
                'card_id' => $validated['card_id'],
                'transaction_description' => $validated['transaction_description'],
                'date' => $validated['date'],
                'transaction_value' => $validated['transaction_value'],
                'installments' => $validated['installments'],
            ]);

            $installments = $this->createInstallments($transaction, $card, (int) $validated['installments']);

            LevelController::completeMission(4);

            return [
                'status' => 'created',
                'transaction' => $transaction,
                'installments' => $installments,
                'category' => $category,
            ];
        });
    }

    /**
     * @throws ValidationException
     */
    private function validate(array $payload): array
    {
        return Validator::make($payload, [
            'transaction_description' => ['required', 'string', 'max:50'],
            'category_id' => ['required', 'integer', 'min:0'],
            'category_description' => ['required_if:category_id,0', 'string', 'max:50'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'type_id' => ['required', 'integer', 'min:1', 'max:2'],
            'transaction_value' => ['required', 'numeric', 'min:0.01'],
            'payment_method_id' => ['required_if:type_id,2', 'prohibited_if:type_id,1', 'integer'],
            'installments' => ['required_if:payment_method_id,4', 'prohibited_unless:payment_method_id,4', 'integer', 'min:1'],
            'card_id' => ['required_if:payment_method_id,4', 'prohibited_unless:payment_method_id,4', 'integer'],
        ], [
            'date.before_or_equal' => 'O campo data deve ser uma data anterior ou igual a data atual.',
            'installments.required_if' => 'O campo parcelas e obrigatorio para compras no cartao de credito.',
            'installments.integer' => 'O campo parcelas deve ser um numero inteiro.',
            'installments.min' => 'O numero de parcelas deve ser no minimo 1.',
            'card_id.required_if' => 'O cartao e obrigatorio para compras no cartao de credito.',
            'transaction_value.numeric' => 'Informe um valor numerico valido para a transacao.',
            'transaction_value.min' => 'Informe um valor de transacao maior que zero.',
        ])->validate();
    }

    /**
     * @throws TransactionCreationException
     */
    private function resolveCard(int $userId, array $validated): ?Card
    {
        if ((int) ($validated['payment_method_id'] ?? 0) !== 4) {
            return null;
        }

        $card = Card::where('user_id', $userId)->find($validated['card_id']);

        if (!$card) {
            throw new TransactionCreationException('Cartao nao encontrado.', 404, [
                'card_id' => ['Cartao nao encontrado.'],
            ]);
        }

        return $card;
    }

    /**
     * @throws TransactionCreationException
     */
    private function resolveCategory(int $userId, array $validated): Category
    {
        if ((int) $validated['category_id'] === 0) {
            return Category::create([
                'user_id' => $userId,
                'category_description' => trim((string) ($validated['category_description'] ?? '')),
                'type_id' => (int) $validated['type_id'],
            ]);
        }

        $category = Category::where('user_id', $userId)->find($validated['category_id']);

        if (!$category) {
            throw new TransactionCreationException('Categoria nao encontrada.', 404, [
                'category_id' => ['Categoria nao encontrada.'],
            ]);
        }

        if ((int) $category->type_id !== (int) $validated['type_id']) {
            throw new TransactionCreationException('A categoria selecionada nao corresponde ao tipo da transacao.', 422, [
                'category_id' => ['A categoria selecionada nao corresponde ao tipo da transacao.'],
            ]);
        }

        return $category;
    }

    /**
     * @return array<int, Installment>
     */
    private function createInstallments(Transaction $transaction, Card $card, int $installments): array
    {
        $transactionValue = (float) $transaction->transaction_value;
        $firstPayDay = $this->resolveFirstInstallmentPayDay((string) $transaction->date, $card);
        $payDays = $this->buildInstallmentDates($firstPayDay, $installments);

        $value = (float) number_format($transactionValue / $installments, 2, '.', '');
        $firstInstallment = $transactionValue - ($value * ($installments - 1));
        $firstInstallment = (float) number_format($firstInstallment, 2, '.', '');

        $response = [];

        for ($i = 1; $i <= $installments; $i++) {
            $installmentValue = ($i === 1) ? $firstInstallment : $value;

            $response[] = Installment::create([
                'transaction_id' => $transaction->id,
                'installment_description' => $transaction->transaction_description . ' ' . $i . '/' . $installments,
                'installment_value' => $installmentValue,
                'card_id' => $transaction->card_id,
                'pay_day' => $payDays[$i - 1],
            ]);
        }

        return $response;
    }

    private function resolveFirstInstallmentPayDay(string $transactionDate, Card $card): string
    {
        $purchaseDate = Carbon::parse($transactionDate)->startOfDay();

        $closureDay = (int) $card->closure;
        $expirationDay = (int) $card->expiration;

        $closureThisMonth = Carbon::create(
            $purchaseDate->year,
            $purchaseDate->month,
            min($closureDay, $purchaseDate->daysInMonth),
            0,
            0,
            0
        );

        if ($expirationDay > $closureDay) {
            $baseMonth = $purchaseDate->lte($closureThisMonth)
                ? $purchaseDate->copy()
                : $purchaseDate->copy()->addMonth();
        } else {
            $baseMonth = $purchaseDate->lte($closureThisMonth)
                ? $purchaseDate->copy()->addMonth()
                : $purchaseDate->copy()->addMonths(2);
        }

        $dueDate = Carbon::create(
            $baseMonth->year,
            $baseMonth->month,
            min($expirationDay, $baseMonth->daysInMonth),
            0,
            0,
            0
        );

        return $dueDate->toDateString();
    }

    private function buildInstallmentDates(string $firstPayDay, int $installments): array
    {
        $dates = [];
        $baseDate = Carbon::parse($firstPayDay)->startOfDay();

        for ($i = 0; $i < $installments; $i++) {
            $dates[] = $baseDate->copy()->addMonths($i)->toDateString();
        }

        return $dates;
    }
}
