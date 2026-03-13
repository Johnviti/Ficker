<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;

class WhatsAppSender
{
    public function sendText(string $phone, string $message): array
    {
        if (!$this->canSend()) {
            return [
                'sent' => false,
                'status' => 'sender_not_configured',
            ];
        }

        $response = Http::withToken((string) config('services.whatsapp.access_token'))
            ->post($this->buildMessagesUrl(), [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($phone, '+'),
                'type' => 'text',
                'text' => [
                    'body' => $message,
                ],
            ]);

        return [
            'sent' => $response->successful(),
            'status' => $response->successful() ? 'sent' : 'failed',
            'http_status' => $response->status(),
            'response_body' => $response->json(),
        ];
    }

    private function canSend(): bool
    {
        return (bool) config('services.whatsapp.enabled', false)
            && (string) config('services.whatsapp.provider', 'meta') === 'meta'
            && (string) config('services.whatsapp.api_version', '') !== ''
            && (string) config('services.whatsapp.access_token', '') !== ''
            && (string) config('services.whatsapp.phone_number_id', '') !== '';
    }

    private function buildMessagesUrl(): string
    {
        return sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            config('services.whatsapp.api_version'),
            config('services.whatsapp.phone_number_id')
        );
    }
}
