<?php

namespace App\Services\Telegram;

use App\Models\Card;
use App\Models\Category;
use App\Models\PaymentMethod;

class TelegramLookupService
{
    public function getCategoriesByType(int $userId, int $typeId): array
    {
        return Category::query()
            ->where('user_id', $userId)
            ->where('type_id', $typeId)
            ->orderBy('category_description')
            ->get()
            ->values()
            ->mapWithKeys(function (Category $category, int $index) {
                $option = (string) ($index + 1);

                return [
                    $option => [
                        'id' => $category->id,
                        'description' => $category->category_description,
                    ],
                ];
            })->all();
    }

    public function getExpensePaymentMethods(): array
    {
        return PaymentMethod::query()
            ->orderBy('id')
            ->get()
            ->values()
            ->mapWithKeys(function (PaymentMethod $paymentMethod) {
                return [
                    (string) $paymentMethod->id => [
                        'id' => $paymentMethod->id,
                        'description' => $paymentMethod->payment_method_description,
                    ],
                ];
            })->all();
    }

    public function getCards(int $userId): array
    {
        return Card::query()
            ->where('user_id', $userId)
            ->orderBy('card_description')
            ->get()
            ->values()
            ->mapWithKeys(function (Card $card, int $index) {
                $option = (string) ($index + 1);

                return [
                    $option => [
                        'id' => $card->id,
                        'description' => $card->card_description,
                    ],
                ];
            })->all();
    }
}
