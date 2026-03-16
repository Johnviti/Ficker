<?php

namespace App\Services\Telegram;

use App\Models\ConversationSession;

class TelegramIntentResolver
{
    public function resolve(string $text, string $state = ConversationSession::STATE_MAIN_MENU): array
    {
        $normalizedText = $this->normalize($text);

        if (in_array($normalizedText, ['/start', 'menu', '0'], true)) {
            return [
                'intent' => 'main_menu',
                'text' => $normalizedText,
            ];
        }

        if ($normalizedText === 'ajuda') {
            return [
                'intent' => 'context_help',
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

        if ($state === ConversationSession::STATE_CARDS_SUMMARY) {
            if (in_array($normalizedText, ['1', '2', '3', '4'], true)) {
                return [
                    'intent' => 'select_card_details',
                    'text' => $normalizedText,
                    'selected_option' => (int) $normalizedText,
                ];
            }

            if ($normalizedText === '5') {
                return [
                    'intent' => 'cards_summary_previous_page',
                    'text' => $normalizedText,
                ];
            }

            if ($normalizedText === '6') {
                return [
                    'intent' => 'cards_summary_next_page',
                    'text' => $normalizedText,
                ];
            }
        }

        if ($state === ConversationSession::STATE_CARD_DETAILS) {
            if ($normalizedText === '2') {
                return [
                    'intent' => 'start_card_invoice_payment_flow',
                    'text' => $normalizedText,
                ];
            }

            if ($normalizedText === '1') {
                return [
                    'intent' => 'card_invoices',
                    'text' => $normalizedText,
                ];
            }
        }

        if ($state === ConversationSession::STATE_CARD_INVOICES) {
            if (in_array($normalizedText, ['1', '2', '3', '4'], true)) {
                return [
                    'intent' => 'select_card_invoice_items',
                    'text' => $normalizedText,
                    'selected_option' => (int) $normalizedText,
                ];
            }

            if ($normalizedText === '5') {
                return [
                    'intent' => 'card_invoices_previous_page',
                    'text' => $normalizedText,
                ];
            }

            if ($normalizedText === '6') {
                return [
                    'intent' => 'card_invoices_next_page',
                    'text' => $normalizedText,
                ];
            }
        }

        if ($state === ConversationSession::STATE_CARD_INVOICE_ITEMS) {
            if ($normalizedText === '5') {
                return [
                    'intent' => 'card_invoice_items_previous_page',
                    'text' => $normalizedText,
                ];
            }

            if ($normalizedText === '6') {
                return [
                    'intent' => 'card_invoice_items_next_page',
                    'text' => $normalizedText,
                ];
            }
        }

        if ($state !== ConversationSession::STATE_MAIN_MENU) {
            return [
                'intent' => 'unknown',
                'text' => $normalizedText,
            ];
        }

        return match (true) {
            in_array($normalizedText, ['1', 'cartoes', 'resumo de cartoes', 'fatura', 'faturas', 'proxima fatura'], true) => [
                'intent' => 'cards_summary',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['2', 'transacoes', 'ultimas transacoes', 'ultimas 5', 'ultimas 5 transacoes'], true) => [
                'intent' => 'transactions_menu',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['3', 'saldo', 'meu saldo'], true) => [
                'intent' => 'get_balance',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['4', 'nova entrada', 'entrada'], true) => [
                'intent' => 'start_income_flow',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['5', 'nova saida', 'saida'], true) => [
                'intent' => 'start_expense_flow',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['6', 'nova categoria', 'categoria'], true) => [
                'intent' => 'start_category_flow',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['7', 'novo cartao', 'cartao novo'], true) => [
                'intent' => 'start_card_flow',
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
