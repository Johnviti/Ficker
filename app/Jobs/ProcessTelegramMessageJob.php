<?php

namespace App\Jobs;

use App\Models\ConversationSession;
use App\Models\TelegramWebhookEvent;
use App\Services\Telegram\AccountLinkService;
use App\Services\Telegram\TelegramAuditService;
use App\Services\Telegram\TelegramCardFlowService;
use App\Services\Telegram\TelegramCardInvoicePaymentFlowService;
use App\Services\Telegram\TelegramCategoryFlowService;
use App\Services\Telegram\TelegramConversationQueryService;
use App\Services\Telegram\TelegramConversationService;
use App\Services\Telegram\TelegramFinancialReplyBuilder;
use App\Services\Telegram\TelegramIntentResolver;
use App\Services\Telegram\TelegramLinkReplyBuilder;
use App\Services\Telegram\TelegramMenuBuilder;
use App\Services\Telegram\TelegramMessageNormalizer;
use App\Services\Telegram\TelegramRateLimitService;
use App\Services\Telegram\TelegramSessionService;
use App\Services\Telegram\TelegramSender;
use App\Services\Telegram\TelegramTransactionFlowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $eventId)
    {
    }

    public function handle(
        TelegramMessageNormalizer $normalizer,
        AccountLinkService $accountLinkService,
        TelegramLinkReplyBuilder $replyBuilder,
        TelegramSessionService $sessionService,
        TelegramConversationService $conversationService,
        TelegramIntentResolver $intentResolver,
        TelegramCategoryFlowService $categoryFlowService,
        TelegramCardFlowService $cardFlowService,
        TelegramCardInvoicePaymentFlowService $cardInvoicePaymentFlowService,
        TelegramConversationQueryService $conversationQueryService,
        TelegramFinancialReplyBuilder $financialReplyBuilder,
        TelegramMenuBuilder $menuBuilder,
        TelegramRateLimitService $rateLimitService,
        TelegramAuditService $auditService,
        TelegramSender $telegramSender,
        TelegramTransactionFlowService $transactionFlowService
    ): void
    {
        $event = TelegramWebhookEvent::find($this->eventId);

        if (!$event) {
            return;
        }

        try {
            $normalizedPayload = $normalizer->normalize($event->payload_json ?? []);
            Log::info('telegram_message_normalized', [
                'event_id' => $event->id,
                'update_id' => $normalizedPayload['update_id'] ?? null,
                'telegram_chat_id' => $normalizedPayload['telegram_chat_id'] ?? null,
                'telegram_user_id' => $normalizedPayload['telegram_user_id'] ?? null,
                'event_type' => $normalizedPayload['event_type'] ?? null,
            ]);

            if (($normalizedPayload['is_supported'] ?? false) !== true) {
                $event->markAsIgnored('Evento fora do escopo do MVP.', $normalizedPayload);
                return;
            }

            $rateLimit = $rateLimitService->check($normalizedPayload['telegram_chat_id'] ?? null);
            $normalizedPayload['rate_limit'] = $rateLimit;

            if (($rateLimit['allowed'] ?? false) !== true) {
                Log::warning('telegram_rate_limit_blocked', [
                    'event_id' => $event->id,
                    'update_id' => $normalizedPayload['update_id'] ?? null,
                    'telegram_chat_id' => $normalizedPayload['telegram_chat_id'] ?? null,
                    'telegram_user_id' => $normalizedPayload['telegram_user_id'] ?? null,
                    'current_hits' => $rateLimit['current_hits'] ?? null,
                    'limit' => $rateLimit['limit'] ?? null,
                ]);

                $normalizedPayload['reply'] = $this->replyToTelegram(
                    $telegramSender,
                    $financialReplyBuilder->buildRateLimitReply(),
                    $normalizedPayload['telegram_chat_id'] ?? null
                );

                $event->markAsProcessed($normalizedPayload);
                return;
            }

            $resolution = $accountLinkService->resolveLinkCode((string) ($normalizedPayload['text'] ?? ''));
            $resolutionForLog = $this->toLoggableArray($resolution);
            $normalizedPayload['link_code_resolution'] = $resolutionForLog;

            if (($resolution['status'] ?? null) === 'valid') {
                $linkResult = $accountLinkService->linkTelegramAccount(
                    $normalizedPayload,
                    $resolution['link_code']
                );

                $normalizedPayload['link_result'] = $linkResult;
                $normalizedPayload['reply'] = $this->replyToTelegram(
                    $telegramSender,
                    $replyBuilder->buildForLinkResult($linkResult),
                    $normalizedPayload['telegram_chat_id'] ?? null
                );
                Log::info('telegram_account_linked', [
                    'event_id' => $event->id,
                    'update_id' => $normalizedPayload['update_id'] ?? null,
                    'telegram_chat_id' => $normalizedPayload['telegram_chat_id'] ?? null,
                    'telegram_user_id' => $normalizedPayload['telegram_user_id'] ?? null,
                    'user_id' => $linkResult['user_id'] ?? null,
                    'telegram_account_id' => $linkResult['telegram_account_id'] ?? null,
                    'reply_success' => $normalizedPayload['reply']['success'] ?? false,
                ]);

                $event->markAsProcessed($normalizedPayload);
                return;
            }

            if (($resolution['status'] ?? null) !== 'not_a_code') {
                $normalizedPayload['reply'] = $this->replyToTelegram(
                    $telegramSender,
                    $replyBuilder->buildForResolution($resolution),
                    $normalizedPayload['telegram_chat_id'] ?? null
                );

                $event->markAsProcessed($normalizedPayload);
                return;
            }

            $sessionResult = $sessionService->resolveActiveAccount($normalizedPayload);
            $normalizedPayload['session'] = $this->toLoggableArray($sessionResult);

            if (($sessionResult['status'] ?? null) !== 'active') {
                Log::info('telegram_session_invalid', [
                    'event_id' => $event->id,
                    'update_id' => $normalizedPayload['update_id'] ?? null,
                    'telegram_chat_id' => $normalizedPayload['telegram_chat_id'] ?? null,
                    'telegram_user_id' => $normalizedPayload['telegram_user_id'] ?? null,
                    'status' => $sessionResult['status'] ?? null,
                    'telegram_account_id' => $sessionResult['telegram_account_id'] ?? null,
                ]);

                $normalizedPayload['reply'] = $this->replyToTelegram(
                    $telegramSender,
                    $financialReplyBuilder->buildSessionReply($sessionResult),
                    $normalizedPayload['telegram_chat_id'] ?? null
                );

                $event->markAsProcessed($normalizedPayload);
                return;
            }

            $conversationSession = $conversationService->resolveSession($normalizedPayload, $sessionResult['user_id']);
            $normalizedPayload['conversation'] = [
                'conversation_session_id' => $conversationSession->id,
                'state' => $conversationSession->state,
                'context' => $conversationSession->context_json ?? [],
            ];

            if ($categoryFlowService->isWizardState($conversationSession->state)) {
                $flowResult = $categoryFlowService->handleInput(
                    $conversationSession,
                    $sessionResult['user_id'],
                    (string) ($normalizedPayload['text'] ?? '')
                );

                $normalizedPayload['category_flow'] = $this->toLoggableArray($flowResult);
                $normalizedPayload['reply'] = $this->replyToTelegram(
                    $telegramSender,
                    $flowResult['message'],
                    $normalizedPayload['telegram_chat_id'] ?? null
                );

                $sessionService->refreshSession($sessionResult['account']);
                $normalizedPayload['session']['status'] = 'active';
                $normalizedPayload['session']['session_refreshed'] = true;

                if (($flowResult['status'] ?? null) === 'created') {
                    $auditService->logAction($normalizedPayload, $sessionResult, 'create_category_confirmed', $normalizedPayload['reply'], [
                        'category_id' => $flowResult['result']['category']->id ?? null,
                        'type_id' => $flowResult['result']['category']->type_id ?? null,
                    ]);
                }

                if (($flowResult['status'] ?? null) === 'cancelled') {
                    $auditService->logAction($normalizedPayload, $sessionResult, 'create_category_cancelled', $normalizedPayload['reply']);
                }

                $event->markAsProcessed($normalizedPayload);
                return;
            }

            if ($transactionFlowService->isWizardState($conversationSession->state)) {
                $flowResult = $transactionFlowService->handleInput(
                    $conversationSession,
                    $sessionResult['user_id'],
                    (string) ($normalizedPayload['text'] ?? '')
                );

                $normalizedPayload['transaction_flow'] = $this->toLoggableArray($flowResult);
                $normalizedPayload['reply'] = $this->replyToTelegram(
                    $telegramSender,
                    $flowResult['message'],
                    $normalizedPayload['telegram_chat_id'] ?? null
                );

                $sessionService->refreshSession($sessionResult['account']);
                $normalizedPayload['session']['status'] = 'active';
                $normalizedPayload['session']['session_refreshed'] = true;

                if (($flowResult['status'] ?? null) === 'created') {
                    $action = (($flowResult['flow'] ?? 'expense') === 'income')
                        ? 'create_income_confirmed'
                        : 'create_expense_confirmed';

                    $auditService->logAction($normalizedPayload, $sessionResult, $action, $normalizedPayload['reply'], [
                        'transaction_id' => $flowResult['result']['transaction']->id ?? null,
                        'installments_count' => count($flowResult['result']['installments'] ?? []),
                    ]);
                }

                if (($flowResult['status'] ?? null) === 'cancelled') {
                    $auditService->logAction($normalizedPayload, $sessionResult, 'create_flow_cancelled', $normalizedPayload['reply'], [
                        'flow' => $flowResult['flow'] ?? null,
                    ]);
                }

                $event->markAsProcessed($normalizedPayload);
                return;
            }

            if ($cardFlowService->isWizardState($conversationSession->state)) {
                $flowResult = $cardFlowService->handleInput(
                    $conversationSession,
                    $sessionResult['user_id'],
                    (string) ($normalizedPayload['text'] ?? '')
                );

                $normalizedPayload['card_flow'] = $this->toLoggableArray($flowResult);
                $normalizedPayload['reply'] = $this->replyToTelegram(
                    $telegramSender,
                    $flowResult['message'],
                    $normalizedPayload['telegram_chat_id'] ?? null
                );

                $sessionService->refreshSession($sessionResult['account']);
                $normalizedPayload['session']['status'] = 'active';
                $normalizedPayload['session']['session_refreshed'] = true;

                if (($flowResult['status'] ?? null) === 'created') {
                    $auditService->logAction($normalizedPayload, $sessionResult, 'create_card_confirmed', $normalizedPayload['reply'], [
                        'card_id' => $flowResult['result']['card']->id ?? null,
                    ]);
                }

                if (($flowResult['status'] ?? null) === 'cancelled') {
                    $auditService->logAction($normalizedPayload, $sessionResult, 'create_card_cancelled', $normalizedPayload['reply']);
                }

                $event->markAsProcessed($normalizedPayload);
                return;
            }

            if ($cardInvoicePaymentFlowService->isWizardState($conversationSession->state)) {
                $flowResult = $cardInvoicePaymentFlowService->handleInput(
                    $conversationSession,
                    $sessionResult['user_id'],
                    (string) ($normalizedPayload['text'] ?? '')
                );

                $normalizedPayload['card_invoice_payment_flow'] = $this->toLoggableArray($flowResult);
                $normalizedPayload['reply'] = $this->replyToTelegram(
                    $telegramSender,
                    $flowResult['message'],
                    $normalizedPayload['telegram_chat_id'] ?? null
                );

                $sessionService->refreshSession($sessionResult['account']);
                $normalizedPayload['session']['status'] = 'active';
                $normalizedPayload['session']['session_refreshed'] = true;

                if (($flowResult['status'] ?? null) === 'created') {
                    $auditService->logAction($normalizedPayload, $sessionResult, 'pay_invoice_confirmed', $normalizedPayload['reply'], [
                        'card_id' => $flowResult['result']['card']->id ?? null,
                        'transaction_id' => $flowResult['result']['payment_transaction']->id ?? null,
                        'pay_day' => $flowResult['result']['pay_day'] ?? null,
                    ]);
                }

                if (($flowResult['status'] ?? null) === 'cancelled') {
                    $auditService->logAction($normalizedPayload, $sessionResult, 'pay_invoice_cancelled', $normalizedPayload['reply'], [
                        'card_id' => $conversationSession->context(ConversationSession::CONTEXT_SELECTED_CARD_ID),
                    ]);
                }

                $event->markAsProcessed($normalizedPayload);
                return;
            }

            $intent = $intentResolver->resolve(
                (string) ($normalizedPayload['text'] ?? ''),
                $conversationSession->state
            );
            $normalizedPayload['intent'] = $intent;
            Log::info('telegram_intent_resolved', [
                'event_id' => $event->id,
                'update_id' => $normalizedPayload['update_id'] ?? null,
                'telegram_chat_id' => $normalizedPayload['telegram_chat_id'] ?? null,
                'telegram_user_id' => $normalizedPayload['telegram_user_id'] ?? null,
                'telegram_account_id' => $sessionResult['telegram_account_id'] ?? null,
                'user_id' => $sessionResult['user_id'] ?? null,
                'intent' => $intent['intent'] ?? null,
            ]);

            $intentName = $intent['intent'] ?? 'unknown';
            $queryResult = match ($intentName) {
                'help', 'main_menu', 'context_help', 'go_back', 'unknown', 'start_category_flow', 'start_income_flow', 'start_expense_flow', 'start_card_invoice_payment_flow', 'start_card_flow' => [],
                'get_balance' => $conversationQueryService->getBalance($sessionResult['user_id']),
                'cards_summary' => $conversationQueryService->getCardsSummary($sessionResult['user_id'], 1, 4),
                'cards_summary_next_page' => $conversationQueryService->getCardsSummary(
                    $sessionResult['user_id'],
                    ((int) $conversationSession->context('page', 1)) + 1,
                    (int) $conversationSession->context('per_page', 4)
                ),
                'cards_summary_previous_page' => $conversationQueryService->getCardsSummary(
                    $sessionResult['user_id'],
                    max(((int) $conversationSession->context('page', 1)) - 1, 1),
                    (int) $conversationSession->context('per_page', 4)
                ),
                'select_card_details' => $conversationQueryService->getCardDetails(
                    $sessionResult['user_id'],
                    (int) data_get(
                        $conversationSession->context('card_options', []),
                        ($intent['selected_option'] ?? 0) . '.id',
                        0
                    )
                ),
                'card_invoices' => $conversationQueryService->getCardInvoices(
                    $sessionResult['user_id'],
                    (int) $conversationSession->context('selected_card_id', 0),
                    1,
                    4
                ),
                'card_invoices_next_page' => $conversationQueryService->getCardInvoices(
                    $sessionResult['user_id'],
                    (int) $conversationSession->context('selected_card_id', 0),
                    ((int) $conversationSession->context('page', 1)) + 1,
                    (int) $conversationSession->context('per_page', 4)
                ),
                'card_invoices_previous_page' => $conversationQueryService->getCardInvoices(
                    $sessionResult['user_id'],
                    (int) $conversationSession->context('selected_card_id', 0),
                    max(((int) $conversationSession->context('page', 1)) - 1, 1),
                    (int) $conversationSession->context('per_page', 4)
                ),
                'select_card_invoice_items' => $conversationQueryService->getCardInvoiceItems(
                    $sessionResult['user_id'],
                    (int) $conversationSession->context('selected_card_id', 0),
                    data_get(
                        $conversationSession->context('invoice_options', []),
                        ($intent['selected_option'] ?? 0) . '.pay_day',
                        null
                    ),
                    1,
                    5
                ),
                'card_invoice_items_next_page' => $conversationQueryService->getCardInvoiceItems(
                    $sessionResult['user_id'],
                    (int) $conversationSession->context('selected_card_id', 0),
                    $conversationSession->context('selected_invoice_pay_day'),
                    ((int) $conversationSession->context('page', 1)) + 1,
                    (int) $conversationSession->context('per_page', 5)
                ),
                'card_invoice_items_previous_page' => $conversationQueryService->getCardInvoiceItems(
                    $sessionResult['user_id'],
                    (int) $conversationSession->context('selected_card_id', 0),
                    $conversationSession->context('selected_invoice_pay_day'),
                    max(((int) $conversationSession->context('page', 1)) - 1, 1),
                    (int) $conversationSession->context('per_page', 5)
                ),
                'transactions_menu' => $conversationQueryService->getTransactionsPage($sessionResult['user_id'], 1, 5),
                'transactions_next_page' => $conversationQueryService->getTransactionsPage(
                    $sessionResult['user_id'],
                    ((int) $conversationSession->context('page', 1)) + 1,
                    (int) $conversationSession->context('per_page', 5)
                ),
                'transactions_previous_page' => $conversationQueryService->getTransactionsPage(
                    $sessionResult['user_id'],
                    max(((int) $conversationSession->context('page', 1)) - 1, 1),
                    (int) $conversationSession->context('per_page', 5)
                ),
                default => [],
            };

            $normalizedPayload['query_result'] = $queryResult;
            if (in_array($intentName, ['get_balance', 'cards_summary', 'cards_summary_next_page', 'cards_summary_previous_page', 'select_card_details', 'card_invoices', 'card_invoices_next_page', 'card_invoices_previous_page', 'select_card_invoice_items', 'card_invoice_items_next_page', 'card_invoice_items_previous_page', 'transactions_menu', 'transactions_next_page', 'transactions_previous_page'], true)) {
                Log::info('telegram_financial_query_executed', [
                    'event_id' => $event->id,
                    'update_id' => $normalizedPayload['update_id'] ?? null,
                    'telegram_chat_id' => $normalizedPayload['telegram_chat_id'] ?? null,
                    'telegram_user_id' => $normalizedPayload['telegram_user_id'] ?? null,
                    'telegram_account_id' => $sessionResult['telegram_account_id'] ?? null,
                    'user_id' => $sessionResult['user_id'] ?? null,
                    'intent' => $intentName,
                ]);
            }

            $message = match ($intentName) {
                'help', 'main_menu' => $this->buildMainMenuReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder
                ),
                'context_help' => $menuBuilder->buildContextHelp($conversationSession->state ?: 'main_menu'),
                'start_income_flow' => $this->startTransactionFlow(
                    $transactionFlowService,
                    $conversationSession,
                    $sessionResult,
                    $normalizedPayload,
                    $auditService,
                    'income'
                ),
                'start_expense_flow' => $this->startTransactionFlow(
                    $transactionFlowService,
                    $conversationSession,
                    $sessionResult,
                    $normalizedPayload,
                    $auditService,
                    'expense'
                ),
                'start_category_flow' => $this->startCategoryFlow(
                    $categoryFlowService,
                    $conversationSession,
                    $sessionResult,
                    $normalizedPayload,
                    $auditService
                ),
                'start_card_flow' => $this->startCardFlow(
                    $cardFlowService,
                    $conversationSession,
                    $sessionResult,
                    $normalizedPayload,
                    $auditService
                ),
                'start_card_invoice_payment_flow' => $this->startCardInvoicePaymentFlow(
                    $cardInvoicePaymentFlowService,
                    $conversationSession,
                    $sessionResult,
                    $normalizedPayload,
                    $auditService
                ),
                'go_back' => $this->buildBackReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder,
                    $conversationQueryService
                ),
                'cards_summary' => $this->buildCardsSummaryReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder,
                    $queryResult
                ),
                'cards_summary_next_page', 'cards_summary_previous_page' => $this->buildCardsSummaryReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder,
                    $queryResult,
                    false
                ),
                'select_card_details' => $this->buildCardDetailsReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder,
                    $queryResult
                ),
                'card_invoices' => $this->buildCardInvoicesReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder,
                    $queryResult,
                    1
                ),
                'card_invoices_next_page', 'card_invoices_previous_page' => $this->buildCardInvoicesReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder,
                    $queryResult,
                    (int) ($queryResult['page'] ?? 1),
                    false
                ),
                'select_card_invoice_items' => $this->buildCardInvoiceItemsReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder,
                    $queryResult,
                    (int) ($queryResult['page'] ?? 1)
                ),
                'card_invoice_items_next_page', 'card_invoice_items_previous_page' => $this->buildCardInvoiceItemsReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder,
                    $queryResult,
                    (int) ($queryResult['page'] ?? 1),
                    false
                ),
                'transactions_menu' => $this->buildTransactionsReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder,
                    $queryResult,
                    1
                ),
                'transactions_next_page', 'transactions_previous_page' => $this->buildTransactionsReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder,
                    $queryResult,
                    (int) ($queryResult['page'] ?? 1),
                    false
                ),
                'get_balance' => $financialReplyBuilder->buildIntentReply($intent, $queryResult),
                default => $menuBuilder->buildUnknownForState($conversationSession->state ?: 'main_menu'),
            };

            $normalizedPayload['reply'] = $this->replyToTelegram(
                $telegramSender,
                $message,
                $normalizedPayload['telegram_chat_id'] ?? null
            );

            if (in_array($intentName, [
                'help',
                'main_menu',
                'context_help',
                'go_back',
                'cards_summary',
                'cards_summary_next_page',
                'cards_summary_previous_page',
                'select_card_invoice_items',
                'card_invoice_items_next_page',
                'card_invoice_items_previous_page',
                'transactions_menu',
                'transactions_next_page',
                'transactions_previous_page',
                'get_balance',
                'start_income_flow',
                'start_expense_flow',
                'start_category_flow',
                'start_card_flow',
                'start_card_invoice_payment_flow',
            ], true)) {
                $sessionService->refreshSession($sessionResult['account']);
                $normalizedPayload['session']['status'] = 'active';
                $normalizedPayload['session']['session_refreshed'] = true;
            }

            $auditService->logIntent($normalizedPayload, $sessionResult, $intent, $normalizedPayload['reply']);
            if (in_array($intentName, ['get_balance', 'cards_summary', 'cards_summary_next_page', 'cards_summary_previous_page', 'select_card_details', 'card_invoices', 'card_invoices_next_page', 'card_invoices_previous_page', 'select_card_invoice_items', 'card_invoice_items_next_page', 'card_invoice_items_previous_page', 'transactions_menu', 'transactions_next_page', 'transactions_previous_page'], true)) {
                Log::info('telegram_audit_logged', [
                    'event_id' => $event->id,
                    'update_id' => $normalizedPayload['update_id'] ?? null,
                    'telegram_chat_id' => $normalizedPayload['telegram_chat_id'] ?? null,
                    'telegram_user_id' => $normalizedPayload['telegram_user_id'] ?? null,
                    'telegram_account_id' => $sessionResult['telegram_account_id'] ?? null,
                    'user_id' => $sessionResult['user_id'] ?? null,
                    'intent' => $intentName,
                ]);
            }

            $event->markAsProcessed($normalizedPayload);
        } catch (\Throwable $e) {
            Log::error('telegram_message_failed', [
                'event_id' => $event->id,
                'update_id' => $normalizedPayload['update_id'] ?? null,
                'telegram_chat_id' => $normalizedPayload['telegram_chat_id'] ?? null,
                'telegram_user_id' => $normalizedPayload['telegram_user_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $event->markAsFailed($e->getMessage(), $normalizedPayload ?? []);
        }
    }

    private function replyToTelegram(TelegramSender $telegramSender, string $message, int|string|null $chatId): array
    {
        if (is_null($chatId)) {
            return [
                'attempted' => false,
                'success' => false,
                'error' => 'telegram_chat_id ausente no payload normalizado.',
            ];
        }

        $reply = $telegramSender->sendMessage($chatId, $message);

        Log::log(
            ($reply['success'] ?? false) ? 'info' : 'warning',
            ($reply['success'] ?? false) ? 'telegram_message_sent' : 'telegram_message_failed',
            [
                'telegram_chat_id' => $chatId,
                'reply_success' => $reply['success'] ?? false,
                'error' => $reply['error'] ?? null,
                'telegram_message_id' => $reply['telegram_message_id'] ?? null,
            ]
        );

        return $reply;
    }

    private function toLoggableArray(array $data): array
    {
        unset($data['link_code'], $data['account']);

        if (isset($data['result']['transaction'])) {
            $data['result'] = [
                'transaction_id' => $data['result']['transaction']->id ?? null,
                'installments_count' => count($data['result']['installments'] ?? []),
            ];
        }

        if (isset($data['result']['payment_transaction'])) {
            $data['result'] = [
                'payment_transaction_id' => $data['result']['payment_transaction']->id ?? null,
                'card_id' => $data['result']['card']->id ?? null,
                'pay_day' => $data['result']['pay_day'] ?? null,
                'invoice_value' => $data['result']['invoice_value'] ?? null,
            ];
        }

        if (isset($data['result']['card'])) {
            $data['result'] = [
                'card_id' => $data['result']['card']->id ?? null,
            ];
        }

        return $data;
    }

    private function buildBackReply(
        TelegramConversationService $conversationService,
        $conversationSession,
        int $userId,
        TelegramMenuBuilder $menuBuilder,
        TelegramConversationQueryService $conversationQueryService
    ): string {
        $currentState = $conversationSession->state;
        $parentPage = (int) $conversationSession->context('parent_page', 1);

        return match ($currentState) {
            ConversationSession::STATE_CARDS_SUMMARY => $this->buildMainMenuReply(
                $conversationService,
                $conversationSession,
                $userId,
                $menuBuilder,
            ),
            ConversationSession::STATE_CARD_DETAILS => $this->buildCardsSummaryReply(
                $conversationService,
                $conversationSession,
                $userId,
                $menuBuilder,
                $conversationQueryService->getCardsSummary(
                    $userId,
                    $parentPage,
                    4
                ),
                false
            ),
            ConversationSession::STATE_CARD_INVOICES => $this->buildCardDetailsReply(
                $conversationService,
                $conversationSession,
                $userId,
                $menuBuilder,
                $conversationQueryService->getCardDetails(
                    $userId,
                    (int) $conversationSession->context(ConversationSession::CONTEXT_SELECTED_CARD_ID, 0)
                ),
                false
            ),
            ConversationSession::STATE_CARD_INVOICE_ITEMS => $this->buildCardInvoicesReply(
                $conversationService,
                $conversationSession,
                $userId,
                $menuBuilder,
                $conversationQueryService->getCardInvoices(
                    $userId,
                    (int) $conversationSession->context(ConversationSession::CONTEXT_SELECTED_CARD_ID, 0),
                    $parentPage,
                    4
                ),
                $parentPage,
                false
            ),
            ConversationSession::STATE_TRANSACTIONS_PAGE => $this->buildMainMenuReply(
                $conversationService,
                $conversationSession,
                $userId,
                $menuBuilder,
            ),
            default => $this->buildMainMenuReply(
                $conversationService,
                $conversationSession,
                $userId,
                $menuBuilder,
            ),
        };
    }

    private function buildCardsSummaryReply(
        TelegramConversationService $conversationService,
        $conversationSession,
        int $userId,
        TelegramMenuBuilder $menuBuilder,
        array $queryResult,
        bool $rememberPrevious = true
    ): string {
        $cardOptions = [];

        foreach (($queryResult['cards'] ?? []) as $index => $card) {
            $cardOptions[(string) ($index + 1)] = [
                'id' => $card['card_id'] ?? null,
                'description' => $card['card_description'] ?? 'Cartao',
            ];
        }

        $conversationService->goToState($conversationSession, 'cards_summary', [
            'card_options' => $cardOptions,
            'page' => (int) ($queryResult['page'] ?? 1),
            'per_page' => (int) ($queryResult['per_page'] ?? 4),
        ], $userId, $rememberPrevious);

        return $menuBuilder->buildCardsSummaryMenu($queryResult);
    }

    private function buildCardDetailsReply(
        TelegramConversationService $conversationService,
        $conversationSession,
        int $userId,
        TelegramMenuBuilder $menuBuilder,
        array $queryResult,
        bool $rememberPrevious = true
    ): string {
        if (($queryResult['invalid_selection'] ?? false) === true) {
            return $menuBuilder->buildUnknownForState(ConversationSession::STATE_CARDS_SUMMARY);
        }

        $conversationService->goToState($conversationSession, ConversationSession::STATE_CARD_DETAILS, [
            ConversationSession::CONTEXT_SELECTED_CARD_ID => (int) ($queryResult['card_id'] ?? 0),
            ConversationSession::CONTEXT_SELECTED_CARD_DESCRIPTION => (string) ($queryResult['card_description'] ?? 'Cartao'),
            ConversationSession::CONTEXT_SELECTED_CARD_PAY_DAY => $queryResult['pay_day'] ?? null,
            ConversationSession::CONTEXT_SELECTED_CARD_CLOSURE_DATE => $queryResult['closure_date'] ?? null,
            ConversationSession::CONTEXT_SELECTED_CARD_INVOICE_TOTAL => (float) ($queryResult['open_total'] ?? $queryResult['invoice_total'] ?? 0),
            ConversationSession::CONTEXT_PARENT_PAGE => (int) $conversationSession->context(ConversationSession::CONTEXT_PARENT_PAGE, $conversationSession->context(ConversationSession::CONTEXT_PAGE, 1)),
        ], $userId, $rememberPrevious);

        return $menuBuilder->buildCardDetailsMenu($queryResult);
    }

    private function buildCardInvoicesReply(
        TelegramConversationService $conversationService,
        $conversationSession,
        int $userId,
        TelegramMenuBuilder $menuBuilder,
        array $queryResult,
        int $page,
        bool $rememberPrevious = true
    ): string {
        $invoiceOptions = [];

        foreach (($queryResult['invoices'] ?? []) as $index => $invoice) {
            $invoiceOptions[(string) ($index + 1)] = [
                'pay_day' => $invoice['pay_day'] ?? null,
                'closure_date' => $invoice['closure_date'] ?? null,
                'total' => $invoice['total'] ?? 0,
            ];
        }

        $conversationService->goToState($conversationSession, ConversationSession::STATE_CARD_INVOICES, [
            ConversationSession::CONTEXT_SELECTED_CARD_ID => (int) ($queryResult['card_id'] ?? 0),
            ConversationSession::CONTEXT_SELECTED_CARD_DESCRIPTION => (string) ($queryResult['card_description'] ?? 'Cartao'),
            ConversationSession::CONTEXT_INVOICE_OPTIONS => $invoiceOptions,
            ConversationSession::CONTEXT_PAGE => $page,
            ConversationSession::CONTEXT_PER_PAGE => (int) ($queryResult['per_page'] ?? 4),
            ConversationSession::CONTEXT_PARENT_PAGE => (int) $conversationSession->context(ConversationSession::CONTEXT_PARENT_PAGE, $conversationSession->context(ConversationSession::CONTEXT_PAGE, 1)),
        ], $userId, $rememberPrevious);

        return $menuBuilder->buildCardInvoicesMenu($queryResult);
    }

    private function buildCardInvoiceItemsReply(
        TelegramConversationService $conversationService,
        $conversationSession,
        int $userId,
        TelegramMenuBuilder $menuBuilder,
        array $queryResult,
        int $page,
        bool $rememberPrevious = true
    ): string {
        if (($queryResult['invalid_selection'] ?? false) === true) {
            return $menuBuilder->buildUnknownForState(ConversationSession::STATE_CARDS_SUMMARY);
        }

        $selectedCardId = (int) ($queryResult['card_id'] ?? 0);
        $selectedCardDescription = (string) ($queryResult['card_description'] ?? 'Cartao');

        $conversationService->goToState($conversationSession, 'card_invoice_items', [
            'selected_card_id' => $selectedCardId,
            'selected_card_description' => $selectedCardDescription,
            'selected_invoice_pay_day' => $queryResult['pay_day'] ?? null,
            'selected_invoice_closure_date' => $queryResult['closure_date'] ?? null,
            'selected_invoice_total' => (float) ($queryResult['open_total'] ?? $queryResult['invoice_total'] ?? 0),
            'page' => $page,
            'per_page' => (int) ($queryResult['per_page'] ?? 5),
            'parent_page' => (int) $conversationSession->context('page', 1),
        ], $userId, $rememberPrevious);

        return $menuBuilder->buildCardInvoiceItemsMenu($queryResult);
    }

    private function buildTransactionsReply(
        TelegramConversationService $conversationService,
        $conversationSession,
        int $userId,
        TelegramMenuBuilder $menuBuilder,
        array $queryResult,
        int $page,
        bool $rememberPrevious = true
    ): string {
        $conversationService->goToState($conversationSession, 'transactions_page', [
            'page' => $page,
            'per_page' => (int) ($queryResult['per_page'] ?? 5),
        ], $userId, $rememberPrevious);

        return $menuBuilder->buildTransactionsMenu($queryResult);
    }

    private function buildMainMenuReply(
        TelegramConversationService $conversationService,
        $conversationSession,
        int $userId,
        TelegramMenuBuilder $menuBuilder
    ): string {
        $conversationService->goToMainMenu($conversationSession, $userId);

        return $menuBuilder->buildMainMenu();
    }

    private function startTransactionFlow(
        TelegramTransactionFlowService $transactionFlowService,
        $conversationSession,
        array $sessionResult,
        array &$normalizedPayload,
        TelegramAuditService $auditService,
        string $flow
    ): string {
        $result = $flow === 'income'
            ? $transactionFlowService->startIncomeFlow($conversationSession, $sessionResult['user_id'])
            : $transactionFlowService->startExpenseFlow($conversationSession, $sessionResult['user_id']);

        $normalizedPayload['transaction_flow'] = $this->toLoggableArray($result);

        $auditService->logAction(
            $normalizedPayload,
            $sessionResult,
            $flow === 'income' ? 'create_income_started' : 'create_expense_started',
            ['success' => true],
            ['flow' => $flow]
        );

        return $result['message'];
    }

    private function startCategoryFlow(
        TelegramCategoryFlowService $categoryFlowService,
        $conversationSession,
        array $sessionResult,
        array &$normalizedPayload,
        TelegramAuditService $auditService
    ): string {
        $result = $categoryFlowService->start($conversationSession, $sessionResult['user_id']);

        $normalizedPayload['category_flow'] = $this->toLoggableArray($result);

        $auditService->logAction(
            $normalizedPayload,
            $sessionResult,
            'create_category_started',
            ['success' => true]
        );

        return $result['message'];
    }

    private function startCardFlow(
        TelegramCardFlowService $cardFlowService,
        $conversationSession,
        array $sessionResult,
        array &$normalizedPayload,
        TelegramAuditService $auditService
    ): string {
        $result = $cardFlowService->start($conversationSession, $sessionResult['user_id']);
        $normalizedPayload['card_flow'] = $this->toLoggableArray($result);

        $auditService->logAction(
            $normalizedPayload,
            $sessionResult,
            'create_card_started',
            ['success' => true]
        );

        return $result['message'];
    }

    private function startCardInvoicePaymentFlow(
        TelegramCardInvoicePaymentFlowService $cardInvoicePaymentFlowService,
        $conversationSession,
        array $sessionResult,
        array &$normalizedPayload,
        TelegramAuditService $auditService
    ): string {
        $result = $cardInvoicePaymentFlowService->start($conversationSession, $sessionResult['user_id']);
        $normalizedPayload['card_invoice_payment_flow'] = $this->toLoggableArray($result);

        if (($result['status'] ?? null) === 'in_progress') {
            $auditService->logAction(
                $normalizedPayload,
                $sessionResult,
                'pay_invoice_started',
                ['success' => true],
                [
                    'card_id' => $conversationSession->context(ConversationSession::CONTEXT_SELECTED_CARD_ID),
                    'pay_day' => $conversationSession->context(ConversationSession::CONTEXT_SELECTED_CARD_PAY_DAY),
                ]
            );
        }

        return $result['message'];
    }
}
