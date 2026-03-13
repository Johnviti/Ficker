<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramMessageJob;
use App\Models\TelegramWebhookEvent;
use App\Services\Telegram\TelegramWebhookValidator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramWebhookValidator $webhookValidator
    ) {
    }

    public function receive(Request $request, string $secret): JsonResponse
    {
        if (!$this->webhookValidator->isEnabled()) {
            return $this->errorResponse('Canal Telegram desabilitado.', 503);
        }

        if (!$this->webhookValidator->isSecretValid($secret)) {
            return $this->errorResponse('Segredo do webhook invalido.', 403);
        }

        $payload = $request->all();

        $event = TelegramWebhookEvent::create([
            'update_id' => data_get($payload, 'update_id'),
            'telegram_user_id' => data_get($payload, 'message.from.id'),
            'telegram_chat_id' => data_get($payload, 'message.chat.id'),
            'event_type' => $this->extractEventType($payload),
            'payload_json' => $payload,
            'processing_status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'received_at' => Carbon::now((string) config('services.telegram.timezone', 'America/Sao_Paulo')),
        ]);

        $event->markAsQueued();

        ProcessTelegramMessageJob::dispatch($event->id);

        return response()->json([
            'message' => 'Webhook recebido com sucesso.'
        ], 200);
    }

    private function extractEventType(array $payload): string
    {
        if (data_get($payload, 'message')) {
            return 'message_received';
        }

        if (data_get($payload, 'callback_query')) {
            return 'callback_query';
        }

        return 'unknown';
    }
}
