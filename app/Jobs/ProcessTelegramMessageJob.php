<?php

namespace App\Jobs;

use App\Models\TelegramWebhookEvent;
use App\Services\Telegram\AccountLinkService;
use App\Services\Telegram\TelegramAuditService;
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
        TelegramConversationQueryService $conversationQueryService,
        TelegramFinancialReplyBuilder $financialReplyBuilder,
        TelegramMenuBuilder $menuBuilder,
        TelegramRateLimitService $rateLimitService,
        TelegramAuditService $auditService,
        TelegramSender $telegramSender
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
                'help', 'main_menu', 'go_back', 'unknown' => [],
                'get_balance' => $conversationQueryService->getBalance($sessionResult['user_id']),
                'cards_summary' => $conversationQueryService->getCardsSummary($sessionResult['user_id']),
                'invoices_menu' => $conversationQueryService->getInvoicesSummary($sessionResult['user_id']),
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
            if (in_array($intentName, ['get_balance', 'cards_summary', 'invoices_menu', 'transactions_menu', 'transactions_next_page', 'transactions_previous_page'], true)) {
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
                'help', 'main_menu' => $menuBuilder->buildMainMenu(),
                'go_back' => $this->buildBackReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder
                ),
                'cards_summary' => $this->buildCardsSummaryReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder,
                    $queryResult
                ),
                'invoices_menu' => $this->buildInvoicesReply(
                    $conversationService,
                    $conversationSession,
                    $sessionResult['user_id'],
                    $menuBuilder,
                    $queryResult
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
                'go_back',
                'cards_summary',
                'invoices_menu',
                'transactions_menu',
                'transactions_next_page',
                'transactions_previous_page',
                'get_balance',
            ], true)) {
                $sessionService->refreshSession($sessionResult['account']);
                $normalizedPayload['session']['status'] = 'active';
                $normalizedPayload['session']['session_refreshed'] = true;
            }

            $auditService->logIntent($normalizedPayload, $sessionResult, $intent, $normalizedPayload['reply']);
            if (in_array($intentName, ['get_balance', 'cards_summary', 'invoices_menu', 'transactions_menu', 'transactions_next_page', 'transactions_previous_page'], true)) {
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

        return $data;
    }

    private function buildBackReply(
        TelegramConversationService $conversationService,
        $conversationSession,
        int $userId,
        TelegramMenuBuilder $menuBuilder
    ): string {
        $updatedSession = $conversationService->goBack($conversationSession, $userId);

        return match ($updatedSession->state) {
            'cards_summary', 'invoices_menu', 'transactions_page' => $menuBuilder->buildMainMenu(),
            default => $menuBuilder->buildMainMenu(),
        };
    }

    private function buildCardsSummaryReply(
        TelegramConversationService $conversationService,
        $conversationSession,
        int $userId,
        TelegramMenuBuilder $menuBuilder,
        array $queryResult
    ): string {
        $conversationService->goToState($conversationSession, 'cards_summary', [], $userId);

        return $menuBuilder->buildCardsSummaryMenu($queryResult);
    }

    private function buildInvoicesReply(
        TelegramConversationService $conversationService,
        $conversationSession,
        int $userId,
        TelegramMenuBuilder $menuBuilder,
        array $queryResult
    ): string {
        $conversationService->goToState($conversationSession, 'invoices_menu', [], $userId);

        return $menuBuilder->buildInvoicesMenu($queryResult);
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
}
