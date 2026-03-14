<?php

namespace App\Services\Telegram;

use App\Models\ConversationSession;

class TelegramIntentResolver
{
    public function resolve(string $text, string $state = ConversationSession::STATE_MAIN_MENU): array
    {
        $normalizedText = $this->normalize($text);

        if (in_array($normalizedText, ['/start', 'ajuda', 'menu', '0'], true)) {
            return [
                'intent' => 'main_menu',
                'text' => $normalizedText,
            ];
        }

        if ($normalizedText === '7' && $state !== ConversationSession::STATE_MAIN_MENU) {
            return [
                'intent' => 'go_back',
                'text' => $normalizedText,
            ];
        }

        if ($state === ConversationSession::STATE_TRANSACTIONS_PAGE) {
            if ($normalizedText === '5') {
                return [
                    'intent' => 'transactions_previous_page',
                    'text' => $normalizedText,
                ];
            }

            if ($normalizedText === '6') {
                return [
                    'intent' => 'transactions_next_page',
                    'text' => $normalizedText,
                ];
            }
        }

        return match (true) {
            in_array($normalizedText, ['1', 'cartoes', 'resumo de cartoes'], true) => [
                'intent' => 'cards_summary',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['2', 'fatura', 'faturas', 'proxima fatura'], true) => [
                'intent' => 'invoices_menu',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['3', 'transacoes', 'ultimas transacoes', 'ultimas 5', 'ultimas 5 transacoes'], true) => [
                'intent' => 'transactions_menu',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['4', 'saldo', 'meu saldo'], true) => [
                'intent' => 'get_balance',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['5', 'nova entrada', 'entrada'], true) => [
                'intent' => 'start_income_flow',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['6', 'nova saida', 'saida'], true) => [
                'intent' => 'start_expense_flow',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['ajuda'], true) => [
                'intent' => 'help',
                'text' => $normalizedText,
            ],
            default => [
                'intent' => 'unknown',
                'text' => $normalizedText,
            ],
        };
    }

    private function normalize(string $text): string
    {
        return strtolower(trim($text));
    }
}
