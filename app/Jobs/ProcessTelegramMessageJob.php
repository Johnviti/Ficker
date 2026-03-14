<?php

namespace App\Jobs;

use App\Models\TelegramWebhookEvent;
use App\Services\Telegram\AccountLinkService;
use App\Services\Telegram\TelegramAuditService;
use App\Services\Telegram\TelegramFinancialQueryService;
use App\Services\Telegram\TelegramFinancialReplyBuilder;
use App\Services\Telegram\TelegramIntentResolver;
use App\Services\Telegram\TelegramLinkReplyBuilder;
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
        TelegramIntentResolver $intentResolver,
        TelegramFinancialQueryService $financialQueryService,
        TelegramFinancialReplyBuilder $financialReplyBuilder,
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

            $intent = $intentResolver->resolve((string) ($normalizedPayload['text'] ?? ''));
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

            $queryResult = match ($intent['intent']) {
                'help', 'unknown' => [],
                'get_balance' => $financialQueryService->getBalance($sessionResult['user_id']),
                'get_next_invoice' => $financialQueryService->getNextInvoice($sessionResult['user_id']),
                'get_last_transactions' => $financialQueryService->getLastTransactions($sessionResult['user_id']),
                default => [],
            };

            $normalizedPayload['query_result'] = $queryResult;
            if (in_array($intent['intent'], ['get_balance', 'get_next_invoice', 'get_last_transactions'], true)) {
                Log::info('telegram_financial_query_executed', [
                    'event_id' => $event->id,
                    'update_id' => $normalizedPayload['update_id'] ?? null,
                    'telegram_chat_id' => $normalizedPayload['telegram_chat_id'] ?? null,
                    'telegram_user_id' => $normalizedPayload['telegram_user_id'] ?? null,
                    'telegram_account_id' => $sessionResult['telegram_account_id'] ?? null,
                    'user_id' => $sessionResult['user_id'] ?? null,
                    'intent' => $intent['intent'] ?? null,
                ]);
            }

            $normalizedPayload['reply'] = $this->replyToTelegram(
                $telegramSender,
                $financialReplyBuilder->buildIntentReply($intent, $queryResult),
                $normalizedPayload['telegram_chat_id'] ?? null
            );

            if (in_array($intent['intent'], ['help', 'get_balance', 'get_next_invoice', 'get_last_transactions'], true)) {
                $sessionService->refreshSession($sessionResult['account']);
                $normalizedPayload['session']['status'] = 'active';
                $normalizedPayload['session']['session_refreshed'] = true;
            }

            $auditService->logIntent($normalizedPayload, $sessionResult, $intent, $normalizedPayload['reply']);
            if (in_array($intent['intent'], ['get_balance', 'get_next_invoice', 'get_last_transactions'], true)) {
                Log::info('telegram_audit_logged', [
                    'event_id' => $event->id,
                    'update_id' => $normalizedPayload['update_id'] ?? null,
                    'telegram_chat_id' => $normalizedPayload['telegram_chat_id'] ?? null,
                    'telegram_user_id' => $normalizedPayload['telegram_user_id'] ?? null,
                    'telegram_account_id' => $sessionResult['telegram_account_id'] ?? null,
                    'user_id' => $sessionResult['user_id'] ?? null,
                    'intent' => $intent['intent'] ?? null,
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
}
