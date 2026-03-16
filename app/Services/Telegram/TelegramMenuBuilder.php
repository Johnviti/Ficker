<?php

namespace App\Services\Telegram;

use App\Models\ConversationSession;

class TelegramMenuBuilder
{
    public function buildMainMenu(): string
    {
        return implode("\n", [
            'Ficker no Telegram',
            'Escolha uma opcao:',
            '',
            '0 - menu principal',
            '1 - cartoes',
            '2 - transacoes',
            '3 - saldo geral',
            '4 - nova entrada',
            '5 - nova saida',
            '6 - nova categoria',
            '7 - novo cartao',
        ]);
    }

    public function buildContextHelp(string $state): string
    {
        return match ($state) {
            ConversationSession::STATE_CARDS_SUMMARY => implode("\n", [
                'Ajuda - cartoes',
                'Voce esta vendo a lista de cartoes.',
                '',
                '1 a 4 - abrir cartao desta pagina',
                '5 - anteriores',
                '6 - proximos',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_DETAILS => implode("\n", [
                'Ajuda - detalhe do cartao',
                'Voce esta no submenu do cartao selecionado.',
                '',
                '1 - ver faturas',
                '2 - pagar fatura atual',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_INVOICES => implode("\n", [
                'Ajuda - faturas do cartao',
                'Voce esta vendo as faturas do cartao selecionado.',
                '',
                '1 a 4 - abrir fatura desta pagina',
                '5 - anteriores',
                '6 - proximas',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_INVOICE_ITEMS => implode("\n", [
                'Ajuda - itens da fatura',
                'Voce esta vendo os itens da fatura selecionada.',
                '',
                '5 - anteriores',
                '6 - proximas',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_METHOD => implode("\n", [
                'Ajuda - pagamento da fatura',
                'Escolha uma forma de pagamento da lista.',
                '',
                '7 - voltar',
                '0 - cancelar',
            ]),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CATEGORY => implode("\n", [
                'Ajuda - categoria do pagamento',
                'Escolha uma categoria da lista.',
                '',
                '7 - voltar',
                '0 - cancelar',
            ]),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CONFIRM => implode("\n", [
                'Ajuda - confirmar pagamento',
                'Revise os dados antes de confirmar.',
                '',
                '1 - confirmar',
                '2 - cancelar',
                '7 - voltar',
                '0 - cancelar',
            ]),
            ConversationSession::STATE_TRANSACTIONS_PAGE => implode("\n", [
                'Ajuda - transacoes',
                'Voce esta vendo a lista de transacoes.',
                '',
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
        $page = (int) ($data['page'] ?? 1);
        $hasPrevious = (bool) ($data['has_previous'] ?? false);
        $hasMore = (bool) ($data['has_more'] ?? false);

        if ($cards === []) {
            return implode("\n", [
                'Cartoes',
                'Voce ainda nao possui cartoes cadastrados.',
                '',
                '7 - voltar',
                '0 - menu principal',
            ]);
        }

        $lines = [
            'Cartoes',
            'Pagina ' . $page,
        ];

        foreach ($cards as $index => $card) {
            $lines[] = '';
            $lines[] = ($index + 1) . ' - ' . ($card['card_description'] ?? 'Cartao sem nome');
            $lines[] = 'Em aberto: ' . $this->money($card['open_total'] ?? 0);
            $lines[] = 'Fatura atual: ' . $this->money($card['invoice_total'] ?? 0);
            $lines[] = 'Fechamento: ' . $this->formatDate($card['closure_date'] ?? null);
            $lines[] = 'Vencimento: ' . $this->formatDate($card['pay_day'] ?? null);
        }

        $lines[] = '';
        $lines[] = 'Abrir cartao: 1 a 4';

        if ($hasPrevious) {
            $lines[] = '5 - anteriores';
        }

        if ($hasMore) {
            $lines[] = '6 - proximos';
        }

        $lines[] = '7 - voltar';
        $lines[] = '0 - menu principal';

        return implode("\n", $lines);
    }

    public function buildCardDetailsMenu(array $data): string
    {
        $lines = [
            'Detalhe do cartao',
            'Cartao: ' . ($data['card_description'] ?? 'Cartao'),
            'Bandeira: ' . ($data['flag_description'] ?? '-'),
            'Em aberto: ' . $this->money($data['open_total'] ?? 0),
            'Fatura atual: ' . $this->money($data['invoice_total'] ?? 0),
            'Status: ' . ($data['invoice_status'] ?? '-'),
            'Fechamento: ' . $this->formatDate($data['closure_date'] ?? null)
                . ' (dia ' . ($data['closure'] ?? '-') . ')',
            'Vencimento: ' . $this->formatDate($data['pay_day'] ?? null)
                . ' (dia ' . ($data['expiration'] ?? '-') . ')',
            '',
            'Acoes:',
            '1 - ver faturas',
        ];

        if (($data['has_open_invoice'] ?? false) === true) {
            $lines[] = '2 - pagar fatura atual';
        }

        $lines[] = '7 - voltar';
        $lines[] = '0 - menu principal';

        return implode("\n", $lines);
    }

    public function buildCardInvoicesMenu(array $data): string
    {
        $invoices = $data['invoices'] ?? [];
        $page = (int) ($data['page'] ?? 1);
        $hasPrevious = (bool) ($data['has_previous'] ?? false);
        $hasMore = (bool) ($data['has_more'] ?? false);

        if ($invoices === []) {
            return implode("\n", [
                'Faturas do cartao',
                'Nao encontrei faturas para este cartao.',
                '',
                '7 - voltar',
                '0 - menu principal',
            ]);
        }

        $lines = [
            'Faturas do cartao',
            'Cartao: ' . ($data['card_description'] ?? 'Cartao'),
            'Pagina ' . $page,
        ];

        foreach ($invoices as $index => $invoice) {
            $lines[] = '';
            $lines[] = ($index + 1) . ' - Vencimento: ' . $this->formatDate($invoice['pay_day'] ?? null);
            $lines[] = 'Fechamento: ' . $this->formatDate($invoice['closure_date'] ?? null);
            $lines[] = 'Total: ' . $this->money($invoice['total'] ?? 0);
            $lines[] = 'Em aberto: ' . $this->money($invoice['open_total'] ?? 0);
            $lines[] = 'Status: ' . ($invoice['status'] ?? '-');
        }

        $lines[] = '';
        $lines[] = 'Abrir fatura: 1 a 4';

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

    public function buildCardInvoiceItemsMenu(array $data): string
    {
        $items = $data['items'] ?? [];
        $page = (int) ($data['page'] ?? 1);
        $hasPrevious = (bool) ($data['has_previous'] ?? false);
        $hasMore = (bool) ($data['has_more'] ?? false);

        $lines = [
            'Itens da fatura',
            'Cartao: ' . ($data['card_description'] ?? 'Cartao'),
            'Fatura: ' . $this->formatDate($data['pay_day'] ?? null),
            'Fechamento: ' . $this->formatDate($data['closure_date'] ?? null),
            'Total: ' . $this->money($data['invoice_total'] ?? 0),
            'Em aberto: ' . $this->money($data['open_total'] ?? 0),
            'Status: ' . ($data['status'] ?? '-'),
            'Pagina ' . $page,
        ];

        if ($items === []) {
            $lines[] = '';
            $lines[] = 'Nao encontrei itens para esta fatura.';
            $lines[] = '';
            $lines[] = '7 - voltar';
            $lines[] = '0 - menu principal';

            return implode("\n", $lines);
        }

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
                'Transacoes',
                'Nao encontrei transacoes para exibir.',
                '',
                '7 - voltar',
                '0 - menu principal',
            ]);
        }

        $lines = [
            'Transacoes',
            'Pagina ' . $page,
        ];

        foreach ($transactions as $index => $transaction) {
            $sign = ((int) ($transaction['type_id'] ?? 2) === 1) ? '+' : '-';
            $lines[] = '';
            $lines[] = ($index + 1) . '. ' . ($transaction['description'] ?? 'Sem descricao');
            $lines[] = '   Valor: ' . $sign . $this->money($transaction['value'] ?? 0);
            $lines[] = '   Data: ' . $this->formatDate($transaction['date'] ?? null);
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
                'Use uma opcao da tela atual:',
                '1 a 4 - abrir cartao desta pagina',
                '5 - anteriores',
                '6 - proximos',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_DETAILS => implode("\n", [
                'Opcao invalida para este submenu.',
                'Use uma opcao da tela atual:',
                '1 - ver faturas',
                '2 - pagar fatura atual',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_INVOICES => implode("\n", [
                'Opcao invalida para este submenu.',
                'Use uma opcao da tela atual:',
                '1 a 4 - abrir fatura desta pagina',
                '5 - anteriores',
                '6 - proximas',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_INVOICE_ITEMS => implode("\n", [
                'Opcao invalida para este submenu.',
                'Use uma opcao da tela atual:',
                '5 - anteriores',
                '6 - proximas',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_METHOD => implode("\n", [
                'Opcao invalida para este passo.',
                'Escolha uma forma de pagamento da lista ou use:',
                '7 - voltar',
                '0 - cancelar',
            ]),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CATEGORY => implode("\n", [
                'Opcao invalida para este passo.',
                'Escolha uma categoria da lista ou use:',
                '7 - voltar',
                '0 - cancelar',
            ]),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CONFIRM => implode("\n", [
                'Opcao invalida para este passo.',
                '1 - confirmar',
                '2 - cancelar',
                '7 - voltar',
                '0 - cancelar',
            ]),
            ConversationSession::STATE_TRANSACTIONS_PAGE => implode("\n", [
                'Opcao invalida para este submenu.',
                'Use uma opcao da tela atual:',
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
