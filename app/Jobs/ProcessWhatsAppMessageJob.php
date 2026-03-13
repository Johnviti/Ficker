<?php

namespace App\Jobs;

use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\AccountLinkService;
use App\Services\WhatsApp\WhatsAppLinkReplyBuilder;
use App\Services\WhatsApp\WhatsAppMessageNormalizer;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $eventId)
    {
    }

    public function handle(
        WhatsAppMessageNormalizer $normalizer,
        AccountLinkService $accountLinkService,
        WhatsAppLinkReplyBuilder $replyBuilder,
        WhatsAppSender $sender
    ): void
    {
        $event = WhatsAppWebhookEvent::find($this->eventId);

        if (!$event) {
            return;
        }

        try {
            $normalizedPayload = $normalizer->normalize($event->payload_json ?? []);

            if (($normalizedPayload['is_supported'] ?? false) !== true) {
                $event->markAsIgnored('Evento fora do escopo do MVP.', $normalizedPayload);
                return;
            }

            $linkResult = $accountLinkService->tryLinkPhone(
                (string) ($normalizedPayload['phone_e164'] ?? ''),
                (string) ($normalizedPayload['text'] ?? '')
            );

            $normalizedPayload['link_result'] = $linkResult;

            $replyMessage = $replyBuilder->build($linkResult);
            $replyPhone = $linkResult['phone_e164'] ?? ($normalizedPayload['phone_e164'] ?? null);

            if (!is_null($replyMessage) && !empty($replyPhone)) {
                $normalizedPayload['reply_result'] = $sender->sendText($replyPhone, $replyMessage);
            } elseif (!is_null($replyMessage)) {
                $normalizedPayload['reply_result'] = [
                    'sent' => false,
                    'status' => 'missing_target_phone',
                ];
            }

            $event->markAsProcessed($normalizedPayload);
        } catch (\Throwable $e) {
            $event->markAsFailed($e->getMessage());
        }
    }
}
