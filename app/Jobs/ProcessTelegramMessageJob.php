<?php

namespace App\Jobs;

use App\Models\TelegramWebhookEvent;
use App\Services\Telegram\AccountLinkService;
use App\Services\Telegram\TelegramFinancialQueryService;
use App\Services\Telegram\TelegramFinancialReplyBuilder;
use App\Services\Telegram\TelegramIntentResolver;
use App\Services\Telegram\TelegramLinkReplyBuilder;
use App\Services\Telegram\TelegramMessageNormalizer;
use App\Services\Telegram\TelegramSessionService;
use App\Services\Telegram\TelegramSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        TelegramSender $telegramSender
    ): void
    {
        $event = TelegramWebhookEvent::find($this->eventId);

        if (!$event) {
            return;
        }

        try {
            $normalizedPayload = $normalizer->normalize($event->payload_json ?? []);

            if (($normalizedPayload['is_supported'] ?? false) !== true) {
                $event->markAsIgnored('Evento fora do escopo do MVP.', $normalizedPayload);
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

            $queryResult = match ($intent['intent']) {
                'help', 'unknown' => [],
                'get_balance' => $financialQueryService->getBalance($sessionResult['user_id']),
                'get_next_invoice' => $financialQueryService->getNextInvoice($sessionResult['user_id']),
                'get_last_transactions' => $financialQueryService->getLastTransactions($sessionResult['user_id']),
                default => [],
            };

            $normalizedPayload['query_result'] = $queryResult;
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

            $event->markAsProcessed($normalizedPayload);
        } catch (\Throwable $e) {
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

        return $telegramSender->sendMessage($chatId, $message);
    }

    private function toLoggableArray(array $data): array
    {
        unset($data['link_code'], $data['account']);

        return $data;
    }
}
