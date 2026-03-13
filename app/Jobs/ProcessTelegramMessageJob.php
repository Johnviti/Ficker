<?php

namespace App\Jobs;

use App\Models\TelegramWebhookEvent;
use App\Services\Telegram\AccountLinkService;
use App\Services\Telegram\TelegramLinkReplyBuilder;
use App\Services\Telegram\TelegramMessageNormalizer;
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
            $normalizedPayload['link_code_resolution'] = $this->toLoggableArray($resolution);

            if (($resolution['status'] ?? null) !== 'valid') {
                $normalizedPayload['reply'] = $this->replyToTelegram(
                    $telegramSender,
                    $replyBuilder->buildForResolution($resolution),
                    $normalizedPayload['telegram_chat_id'] ?? null
                );

                $event->markAsProcessed($normalizedPayload);
                return;
            }

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

    private function toLoggableArray(array $resolution): array
    {
        unset($resolution['link_code']);

        return $resolution;
    }
}
