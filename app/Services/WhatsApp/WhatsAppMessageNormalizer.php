<?php

namespace App\Services\WhatsApp;

use Carbon\Carbon;

class WhatsAppMessageNormalizer
{
    public function normalize(array $payload): array
    {
        $message = data_get($payload, 'entry.0.changes.0.value.messages.0');
        $contact = data_get($payload, 'entry.0.changes.0.value.contacts.0');

        if (!$message || !is_array($message)) {
            return [
                'provider' => 'meta',
                'event_type' => 'unknown',
                'is_supported' => false,
            ];
        }

        if (($message['type'] ?? null) !== 'text') {
            return [
                'provider' => 'meta',
                'event_type' => 'message_received',
                'message_id' => $message['id'] ?? null,
                'phone_e164' => $contact['wa_id'] ?? $message['from'] ?? null,
                'received_at' => isset($message['timestamp'])
                    ? Carbon::createFromTimestamp((int) $message['timestamp'])->toDateTimeString()
                    : now()->toDateTimeString(),
                'is_supported' => false,
            ];
        }

        $text = trim((string) data_get($message, 'text.body', ''));

        return [
            'provider' => 'meta',
            'event_type' => 'message_received',
            'message_id' => $message['id'] ?? null,
            'phone_e164' => $contact['wa_id'] ?? $message['from'] ?? null,
            'text' => $text,
            'received_at' => isset($message['timestamp'])
                ? Carbon::createFromTimestamp((int) $message['timestamp'])->toDateTimeString()
                : now()->toDateTimeString(),
            'is_supported' => $text !== '',
        ];
    }
}
