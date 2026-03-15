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

    public function getInvoicePaymentMethods(): array
    {
        return PaymentMethod::query()
            ->where('id', '!=', 4)
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

    public function getInvoicePaymentCategoryOptions(int $userId): array
    {
        $options = [
            '1' => [
                'id' => null,
                'description' => 'Pagamento de fatura',
                'mode' => 'default',
            ],
        ];

        $categories = Category::query()
            ->where('user_id', $userId)
            ->where('type_id', 2)
            ->orderBy('category_description')
            ->get()
            ->values();

        $optionNumber = 2;

        foreach ($categories as $category) {
            if ($category->category_description === 'Pagamento de fatura') {
                continue;
            }

            $options[(string) $optionNumber] = [
                'id' => $category->id,
                'description' => $category->category_description,
                'mode' => 'existing',
            ];

            $optionNumber++;
        }

        return $options;
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

    public function getCardsPage(int $userId, int $page = 1, int $perPage = 4): array
    {
        $cards = Card::query()
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
            'options' => $pageCards->mapWithKeys(function (Card $card, int $index) {
                $option = (string) ($index + 1);

                return [
                    $option => [
                        'id' => $card->id,
                        'description' => $card->card_description,
                    ],
                ];
            })->all(),
        ];
    }
}
