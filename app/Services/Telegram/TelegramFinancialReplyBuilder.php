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
                'Depois disso, voce pode pedir:',
                '0 - ajuda',
                '1 - saldo',
                '2 - fatura',
                '3 - ultimas transacoes',
            ]),
            'revoked', 'not_linked' => implode("\n", [
                'Seu Telegram ainda nao esta conectado ao Ficker.',
                'Gere um codigo no app e envie aqui para vincular sua conta.',
                '',
                'Depois disso, voce pode pedir:',
                '0 - ajuda',
                '1 - saldo',
                '2 - fatura',
                '3 - ultimas transacoes',
            ]),
            default => implode("\n", [
                'Nao consegui validar sua sessao no Telegram agora.',
                'Tente novamente em instantes ou use:',
                '0 - ajuda',
            ]),
        };
    }

    public function buildIntentReply(array $intent, array $data = []): string
    {
        return match ($intent['intent'] ?? 'unknown') {
            'help' => $this->buildHelpReply(),
            'get_balance' => $this->buildBalanceReply($data),
            'get_next_invoice' => $this->buildNextInvoiceReply($data),
            'get_last_transactions' => $this->buildLastTransactionsReply($data),
            default => $this->buildUnknownReply(),
        };
    }

    private function buildHelpReply(): string
    {
        return implode("\n", [
            'Voce pode pedir:',
            '0 - ajuda',
            '1 - saldo',
            '2 - fatura',
            '3 - ultimas transacoes',
        ]);
    }

    private function buildBalanceReply(array $data): string
    {
        return implode("\n", [
            'Saldo atual: ' . $this->money($data['balance'] ?? 0),
            'Gasto real no mes: ' . $this->money($data['real_spending'] ?? 0),
            'Gasto planejado: ' . $this->money($data['planned_spending'] ?? 0),
        ]);
    }

    private function buildNextInvoiceReply(array $data): string
    {
        if (($data['has_open_invoice'] ?? false) !== true) {
            return 'Voce nao possui fatura em aberto no momento.';
        }

        return implode("\n", [
            'Proxima fatura: ' . $this->money($data['total'] ?? 0),
            'Cartao: ' . ($data['card_description'] ?? 'Nao identificado'),
            'Vencimento: ' . $this->formatDate($data['pay_day'] ?? null),
        ]);
    }

    private function buildLastTransactionsReply(array $data): string
    {
        $transactions = $data['transactions'] ?? [];

        if ($transactions === []) {
            return 'Nao encontrei transacoes recentes para sua conta.';
        }

        $lines = ['Ultimas transacoes:'];

        foreach ($transactions as $index => $transaction) {
            $sign = ((int) ($transaction['type_id'] ?? 2) === 1) ? '+' : '-';
            $lines[] = ($index + 1) . '. '
                . ($transaction['description'] ?? 'Sem descricao')
                . ' - ' . $sign . $this->money($transaction['value'] ?? 0)
                . ' - ' . $this->formatDate($transaction['date'] ?? null);
        }

        return implode("\n", $lines);
    }

    private function buildUnknownReply(): string
    {
        return implode("\n", [
            'Nao entendi o que voce quer consultar.',
            'Tente uma destas opcoes:',
            '0 - ajuda',
            '1 - saldo',
            '2 - fatura',
            '3 - ultimas transacoes',
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
