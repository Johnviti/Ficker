<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppMessageJob;
use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppSignatureValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppSignatureValidator $signatureValidator
    ) {
    }

    public function verify(Request $request): Response|JsonResponse
    {
        if (!$this->signatureValidator->isEnabled()) {
            return $this->errorResponse('Canal WhatsApp desabilitado.', 503);
        }

        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        if (!$this->signatureValidator->isVerificationTokenValid($token) || is_null($challenge)) {
            return $this->errorResponse('Token de verificacao invalido.', 403);
        }

        return response((string) $challenge, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    public function receive(Request $request): JsonResponse
    {
        if (!$this->signatureValidator->isEnabled()) {
            return $this->errorResponse('Canal WhatsApp desabilitado.', 503);
        }

        if (!$this->signatureValidator->isRequestSignatureValid($request)) {
            return $this->errorResponse('Assinatura invalida.', 403);
        }

        $payload = $request->all();

        $event = WhatsAppWebhookEvent::create([
            'provider' => (string) config('services.whatsapp.provider', 'meta'),
            'event_type' => $this->extractEventType($payload),
            'phone_e164' => $this->extractPhone($payload),
            'provider_message_id' => $this->extractProviderMessageId($payload),
            'payload_json' => $payload,
            'headers_json' => $request->headers->all(),
            'processing_status' => WhatsAppWebhookEvent::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        $event->markAsQueued();

        ProcessWhatsAppMessageJob::dispatch($event->id);

        return response()->json([
            'message' => 'Webhook recebido com sucesso.'
        ], 200);
    }

    private function extractEventType(array $payload): string
    {
        if (data_get($payload, 'entry.0.changes.0.value.messages.0')) {
            return 'message_received';
        }

        if (data_get($payload, 'entry.0.changes.0.value.statuses.0')) {
            return 'status_update';
        }

        return 'unknown';
    }

    private function extractPhone(array $payload): ?string
    {
        return data_get($payload, 'entry.0.changes.0.value.contacts.0.wa_id')
            ?? data_get($payload, 'entry.0.changes.0.value.messages.0.from');
    }

    private function extractProviderMessageId(array $payload): ?string
    {
        return data_get($payload, 'entry.0.changes.0.value.messages.0.id')
            ?? data_get($payload, 'entry.0.changes.0.value.statuses.0.id');
    }
}
