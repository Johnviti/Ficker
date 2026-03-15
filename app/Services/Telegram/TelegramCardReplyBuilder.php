<?php

namespace App\Services\Telegram;

class TelegramCardReplyBuilder
{
    public function buildDescriptionPrompt(): string
    {
        return implode("\n", [
            'Novo cartao.',
            'Digite a descricao do cartao.',
            'Exemplo: Cartao Viagem',
            '',
            '7 - voltar',
            '0 - cancelar',
        ]);
    }

    public function buildFlagPrompt(array $flags): string
    {
        if ($flags === []) {
            return implode("\n", [
                'Nao encontrei bandeiras disponiveis.',
                '',
                '7 - voltar',
                '0 - cancelar',
            ]);
        }

        $lines = ['Escolha a bandeira do cartao:'];

        foreach ($flags as $option => $flag) {
            $lines[] = $option . ' - ' . ($flag['description'] ?? 'Bandeira');
        }

        $lines[] = '';
        $lines[] = '7 - voltar';
        $lines[] = '0 - cancelar';

        return implode("\n", $lines);
    }

    public function buildClosurePrompt(): string
    {
        return implode("\n", [
            'Digite o dia do fechamento.',
            'Exemplo: 15',
            '',
            '7 - voltar',
            '0 - cancelar',
        ]);
    }

    public function buildExpirationPrompt(): string
    {
        return implode("\n", [
            'Digite o dia do vencimento.',
            'Exemplo: 3',
            '',
            '7 - voltar',
            '0 - cancelar',
        ]);
    }

    public function buildConfirmationPrompt(array $draft): string
    {
        return implode("\n", [
            'Confirme os dados do cartao:',
            'Descricao: ' . ($draft['card_description'] ?? '-'),
            'Bandeira: ' . ($draft['resolved_flag_description'] ?? '-'),
            'Fechamento: dia ' . ($draft['closure'] ?? '-'),
            'Vencimento: dia ' . ($draft['expiration'] ?? '-'),
            '',
            '1 - confirmar',
            '2 - cancelar',
            '7 - voltar',
            '0 - cancelar',
        ]);
    }

    public function buildValidationError(string $message, string $prompt): string
    {
        return $message . "\n\n" . $prompt;
    }

    public function buildCancelled(): string
    {
        return implode("\n", [
            'Criacao de cartao cancelada.',
            '',
            '0 - menu principal',
        ]);
    }

    public function buildCreatedSuccess(array $result): string
    {
        return implode("\n", [
            'Cartao criado com sucesso.',
            'Descricao: ' . ($result['card']->card_description ?? '-'),
            '',
            '0 - menu principal',
        ]);
    }
}
