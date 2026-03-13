<?php

namespace App\Services\Telegram;

class TelegramIntentResolver
{
    public function resolve(string $text): array
    {
        $normalizedText = $this->normalize($text);

        return match (true) {
            in_array($normalizedText, ['/start', 'ajuda', 'menu'], true) => [
                'intent' => 'help',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['saldo', 'meu saldo'], true) => [
                'intent' => 'get_balance',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['fatura', 'proxima fatura'], true) => [
                'intent' => 'get_next_invoice',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['ultimas transacoes', 'ultimas 5', 'ultimas 5 transacoes'], true) => [
                'intent' => 'get_last_transactions',
                'text' => $normalizedText,
            ],
            default => [
                'intent' => 'unknown',
                'text' => $normalizedText,
            ],
        };
    }

    private function normalize(string $text): string
    {
        return strtolower(trim($text));
    }
}
