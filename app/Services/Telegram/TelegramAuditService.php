<?php

namespace App\Services\Telegram;

use App\Models\AuditAccessLog;

class TelegramAuditService
{
    public function logIntent(array $normalizedPayload, array $sessionResult, array $intent, array $reply): void
    {
        $action = $intent['intent'] ?? null;

        if (!in_array($action, [
            'get_balance',
            'cards_summary',
            'cards_summary_next_page',
            'cards_summary_previous_page',
            'select_card_details',
            'card_invoices',
            'card_invoices_next_page',
            'card_invoices_previous_page',
            'select_card_invoice_items',
            'card_invoice_items_next_page',
            'card_invoice_items_previous_page',
            'transactions_menu',
            'transactions_next_page',
            'transactions_previous_page',
        ], true)) {
            return;
        }

        $userId = $sessionResult['user_id'] ?? null;

        if (is_null($userId)) {
            return;
        }

        AuditAccessLog::create([
            'user_id' => $userId,
            'channel' => 'telegram',
            'action' => $action,
            'metadata_json' => [
                'update_id' => $normalizedPayload['update_id'] ?? null,
                'telegram_chat_id' => $normalizedPayload['telegram_chat_id'] ?? null,
                'telegram_user_id' => $normalizedPayload['telegram_user_id'] ?? null,
                'telegram_account_id' => $sessionResult['telegram_account_id'] ?? null,
                'reply_success' => $reply['success'] ?? false,
            ],
        ]);
    }

    public function logAction(array $normalizedPayload, array $sessionResult, string $action, array $reply, array $metadata = []): void
    {
        $userId = $sessionResult['user_id'] ?? null;

        if (is_null($userId)) {
            return;
        }

        AuditAccessLog::create([
            'user_id' => $userId,
            'channel' => 'telegram',
            'action' => $action,
            'metadata_json' => array_merge([
                'update_id' => $normalizedPayload['update_id'] ?? null,
                'telegram_chat_id' => $normalizedPayload['telegram_chat_id'] ?? null,
                'telegram_user_id' => $normalizedPayload['telegram_user_id'] ?? null,
                'telegram_account_id' => $sessionResult['telegram_account_id'] ?? null,
                'reply_success' => $reply['success'] ?? false,
            ], $metadata),
        ]);
    }
}
