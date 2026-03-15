<?php

namespace App\Services\Telegram;

use App\Models\ConversationSession;

class TelegramMenuBuilder
{
    public function buildMainMenu(): string
    {
        return implode("\n", [
            'Menu principal:',
            '0 - menu principal',
            '1 - cartoes',
            '3 - transacoes',
            '4 - saldo geral',
            '5 - nova entrada',
            '6 - nova saida',
            '8 - nova categoria',
        ]);
    }

    public function buildContextHelp(string $state): string
    {
        return match ($state) {
            ConversationSession::STATE_CARDS_SUMMARY => implode("\n", [
                'Voce esta em Cartoes.',
                'Use:',
                'Escolha o numero de um cartao para ver os itens da fatura atual.',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_INVOICE_ITEMS => implode("\n", [
                'Voce esta nos itens da fatura atual do cartao selecionado.',
                'Use:',
                '5 - anteriores',
                '6 - proximas',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_TRANSACTIONS_PAGE => implode("\n", [
                'Voce esta em Transacoes.',
                'Use:',
                '5 - anteriores',
                '6 - proximas',
                '7 - voltar',
                '0 - menu principal',
            ]),
            default => $this->buildMainMenu(),
        };
    }

    public function buildCardsSummaryMenu(array $data): string
    {
        $cards = $data['cards'] ?? [];

        if ($cards === []) {
            return implode("\n", [
                'Voce ainda nao possui cartoes cadastrados.',
                '',
                '7 - voltar',
                '0 - menu principal',
            ]);
        }

        $lines = ['Cartoes:'];

        foreach ($cards as $index => $card) {
            $lines[] = ($index + 1) . ' - ' . ($card['card_description'] ?? 'Cartao sem nome');
            $lines[] = '  Em aberto: ' . $this->money($card['open_total'] ?? 0);
            $lines[] = '  Fatura atual: ' . $this->money($card['invoice_total'] ?? 0);
            $lines[] = '  Fechamento: ' . $this->formatDate($card['closure_date'] ?? null)
                . ' (dia ' . ($card['closure'] ?? '-') . ')';
            $lines[] = '  Vencimento: ' . $this->formatDate($card['pay_day'] ?? null)
                . ' (dia ' . ($card['expiration'] ?? '-') . ')';
        }

        $lines[] = '';
        $lines[] = 'Escolha o numero de um cartao para ver os itens da fatura atual.';
        $lines[] = '7 - voltar';
        $lines[] = '0 - menu principal';

        return implode("\n", $lines);
    }

    public function buildInvoicesMenu(array $data): string
    {
        $cards = $data['cards'] ?? [];

        if ($cards === []) {
            return implode("\n", [
                'Voce ainda nao possui cartoes cadastrados.',
                '',
                '7 - voltar',
                '0 - menu principal',
            ]);
        }

        $lines = ['Faturas dos cartoes:'];

        foreach ($cards as $card) {
            $lines[] = '- ' . ($card['card_description'] ?? 'Cartao sem nome');
            $lines[] = '  Fatura atual: ' . $this->money($card['open_total'] ?? 0);
            $lines[] = '  Fechamento: ' . $this->formatDate($card['closure_date'] ?? null)
                . ' (dia ' . ($card['closure'] ?? '-') . ')';
            $lines[] = '  Vencimento: ' . $this->formatDate($card['pay_day'] ?? null)
                . ' (dia ' . ($card['expiration'] ?? '-') . ')';
        }

        $lines[] = '';
        $lines[] = '7 - voltar';
        $lines[] = '0 - menu principal';

        return implode("\n", $lines);
    }

    public function buildCardInvoiceItemsMenu(array $data): string
    {
        $items = $data['items'] ?? [];
        $page = (int) ($data['page'] ?? 1);
        $hasPrevious = (bool) ($data['has_previous'] ?? false);
        $hasMore = (bool) ($data['has_more'] ?? false);

        if ($items === []) {
            return implode("\n", [
                'Nao encontrei itens em aberto para a fatura atual deste cartao.',
                'Cartao: ' . ($data['card_description'] ?? 'Cartao'),
                '',
                '7 - voltar',
                '0 - menu principal',
            ]);
        }

        $lines = [
            'Cartao: ' . ($data['card_description'] ?? 'Cartao'),
            'Fatura atual - pagina ' . $page,
            'Fechamento: ' . $this->formatDate($data['closure_date'] ?? null),
            'Vencimento: ' . $this->formatDate($data['pay_day'] ?? null),
        ];

        foreach ($items as $index => $item) {
            $lines[] = '';
            $lines[] = ($index + 1) . '. ' . ($item['description'] ?? 'Sem descricao');
            $lines[] = '   Data: ' . $this->formatDate($item['date'] ?? null);
            $lines[] = '   Categoria: ' . ($item['category_description'] ?? '-');
            $lines[] = '   Valor: -' . $this->money($item['value'] ?? 0);
            $lines[] = '   Parcelas: ' . ($item['installments_label'] ?? '1x');
        }

        $lines[] = '';

        if ($hasPrevious) {
            $lines[] = '5 - anteriores';
        }

        if ($hasMore) {
            $lines[] = '6 - proximas';
        }

        $lines[] = '7 - voltar';
        $lines[] = '0 - menu principal';

        return implode("\n", $lines);
    }

    public function buildTransactionsMenu(array $data): string
    {
        $transactions = $data['transactions'] ?? [];
        $page = (int) ($data['page'] ?? 1);
        $hasPrevious = (bool) ($data['has_previous'] ?? false);
        $hasMore = (bool) ($data['has_more'] ?? false);

        if ($transactions === []) {
            return implode("\n", [
                'Nao encontrei transacoes para exibir.',
                '',
                '7 - voltar',
                '0 - menu principal',
            ]);
        }

        $lines = ['Transacoes - pagina ' . $page . ':'];

        foreach ($transactions as $index => $transaction) {
            $sign = ((int) ($transaction['type_id'] ?? 2) === 1) ? '+' : '-';
            $lines[] = ($index + 1) . '. '
                . ($transaction['description'] ?? 'Sem descricao')
                . ' - ' . $sign . $this->money($transaction['value'] ?? 0)
                . ' - ' . $this->formatDate($transaction['date'] ?? null);
        }

        $lines[] = '';

        if ($hasPrevious) {
            $lines[] = '5 - anteriores';
        }

        if ($hasMore) {
            $lines[] = '6 - proximas';
        }

        $lines[] = '7 - voltar';
        $lines[] = '0 - menu principal';

        return implode("\n", $lines);
    }

    public function buildUnknownForState(string $state): string
    {
        return match ($state) {
            ConversationSession::STATE_CARDS_SUMMARY => implode("\n", [
                'Opcao invalida para este submenu.',
                'Escolha o numero de um cartao, ou use:',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_INVOICE_ITEMS => implode("\n", [
                'Opcao invalida para este submenu.',
                '5 - anteriores',
                '6 - proximas',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_TRANSACTIONS_PAGE => implode("\n", [
                'Opcao invalida para este submenu.',
                '5 - anteriores',
                '6 - proximas',
                '7 - voltar',
                '0 - menu principal',
            ]),
            default => $this->buildMainMenu(),
        };
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
