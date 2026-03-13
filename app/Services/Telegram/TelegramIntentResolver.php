<?php

namespace App\Services\Telegram;

class TelegramIntentResolver
{
    public function resolve(string $text): array
    {
        $normalizedText = $this->normalize($text);

        return match (true) {
            in_array($normalizedText, ['/start', 'ajuda', 'menu', '0'], true) => [
                'intent' => 'help',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['1', 'saldo', 'meu saldo'], true) => [
                'intent' => 'get_balance',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['2', 'fatura', 'proxima fatura'], true) => [
                'intent' => 'get_next_invoice',
                'text' => $normalizedText,
            ],
            in_array($normalizedText, ['3', 'ultimas transacoes', 'ultimas 5', 'ultimas 5 transacoes'], true) => [
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
