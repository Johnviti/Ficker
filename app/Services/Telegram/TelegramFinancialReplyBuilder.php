<?php

namespace App\Services\Telegram;

class TelegramFinancialReplyBuilder
{
    public function buildSessionReply(array $sessionResult): string
    {
        return match ($sessionResult['status'] ?? 'not_linked') {
            'session_expired' => implode("\n", [
                'Seu acesso no Telegram expirou por inatividade.',
                'Gere um novo codigo no Ficker para reconectar sua conta.',
                '',
                'Menu principal:',
                '0 - menu principal',
                'ajuda - ver opcoes',
                '1 - resumo de cartoes',
                '2 - faturas',
                '3 - transacoes',
                '4 - saldo geral',
                '5 - nova entrada',
                '6 - nova saida',
                '8 - nova categoria',
            ]),
            'revoked', 'not_linked' => implode("\n", [
                'Seu Telegram ainda nao esta conectado ao Ficker.',
                'Gere um codigo no app e envie aqui para vincular sua conta.',
                '',
                'Menu principal:',
                '0 - menu principal',
                'ajuda - ver opcoes',
                '1 - resumo de cartoes',
                '2 - faturas',
                '3 - transacoes',
                '4 - saldo geral',
                '5 - nova entrada',
                '6 - nova saida',
                '8 - nova categoria',
            ]),
            default => implode("\n", [
                'Nao consegui validar sua sessao no Telegram agora.',
                'Tente novamente em instantes ou use:',
                'ajuda - ver opcoes',
                '0 - menu principal',
            ]),
        };
    }

    public function buildIntentReply(array $intent, array $data = []): string
    {
        return match ($intent['intent'] ?? 'unknown') {
            'help', 'main_menu' => $this->buildHelpReply(),
            'get_balance' => $this->buildBalanceReply($data),
            default => $this->buildUnknownReply(),
        };
    }

    public function buildRateLimitReply(): string
    {
        return 'Voce enviou muitas mensagens em pouco tempo. Aguarde um instante e tente novamente.';
    }

    private function buildHelpReply(): string
    {
        return implode("\n", [
            'Menu principal:',
            '0 - menu principal',
            'ajuda - ver opcoes',
            '1 - resumo de cartoes',
            '2 - faturas',
            '3 - transacoes',
            '4 - saldo geral',
            '5 - nova entrada',
            '6 - nova saida',
            '8 - nova categoria',
        ]);
    }

    private function buildBalanceReply(array $data): string
    {
        return implode("\n", [
            'Saldo atual: ' . $this->money($data['balance'] ?? 0),
            'Gasto real no mes: ' . $this->money($data['real_spending'] ?? 0),
            'Gasto planejado: ' . $this->money($data['planned_spending'] ?? 0),
            '',
            'ajuda - ver opcoes',
            '0 - menu principal',
        ]);
    }

    private function buildUnknownReply(): string
    {
        return implode("\n", [
            'Nao entendi o que voce quer consultar.',
            'Use uma das opcoes do menu:',
            'ajuda - ver opcoes',
            '0 - menu principal',
            '1 - resumo de cartoes',
            '2 - faturas',
            '3 - transacoes',
            '4 - saldo geral',
            '5 - nova entrada',
            '6 - nova saida',
            '8 - nova categoria',
        ]);
    }

    private function money(float|int $value): string
    {
        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }

    private function formatDate(?string $date): string
    {
        if (is_null($date) || $date === '') {
            return '-';
        }

        return \Carbon\Carbon::parse($date)->format('d/m/Y');
    }
}
