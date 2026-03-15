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
                'Voce esta em Cartoes.',
                'Use:',
                '1 a 4 - abrir cartao desta pagina',
                '5 - anteriores',
                '6 - proximos',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_DETAILS => implode("\n", [
                'Voce esta no submenu do cartao.',
                'Use:',
                '1 - ver faturas',
                '2 - pagar fatura atual',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_INVOICES => implode("\n", [
                'Voce esta na lista de faturas do cartao.',
                'Use:',
                '1 a 4 - abrir fatura desta pagina',
                '5 - anteriores',
                '6 - proximas',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_INVOICE_ITEMS => implode("\n", [
                'Voce esta nos itens da fatura selecionada.',
                'Use:',
                '5 - anteriores',
                '6 - proximas',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_METHOD => implode("\n", [
                'Voce esta pagando a fatura do cartao selecionado.',
                'Escolha uma forma de pagamento listada ou use:',
                '7 - voltar',
                '0 - cancelar',
            ]),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CATEGORY => implode("\n", [
                'Voce esta escolhendo a categoria do pagamento da fatura.',
                'Escolha uma categoria listada ou use:',
                '7 - voltar',
                '0 - cancelar',
            ]),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CONFIRM => implode("\n", [
                'Voce esta confirmando o pagamento da fatura.',
                'Use:',
                '1 - confirmar',
                '2 - cancelar',
                '7 - voltar',
                '0 - cancelar',
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
        $page = (int) ($data['page'] ?? 1);
        $hasPrevious = (bool) ($data['has_previous'] ?? false);
        $hasMore = (bool) ($data['has_more'] ?? false);

        if ($cards === []) {
            return implode("\n", [
                'Voce ainda nao possui cartoes cadastrados.',
                '',
                '7 - voltar',
                '0 - menu principal',
            ]);
        }

        $lines = ['Cartoes - pagina ' . $page . ':'];

        foreach ($cards as $index => $card) {
            $lines[] = ($index + 1) . ' - ' . ($card['card_description'] ?? 'Cartao sem nome');
            $lines[] = '  Em aberto: ' . $this->money($card['open_total'] ?? 0);
            $lines[] = '  Fatura atual: ' . $this->money($card['invoice_total'] ?? 0);
            $lines[] = '  Fechamento: ' . $this->formatDate($card['closure_date'] ?? null);
            $lines[] = '  Vencimento: ' . $this->formatDate($card['pay_day'] ?? null);
        }

        $lines[] = '';

        if ($hasPrevious) {
            $lines[] = '5 - anteriores';
        }

        if ($hasMore) {
            $lines[] = '6 - proximos';
        }

        $lines[] = 'Escolha 1 a 4 para abrir um cartao.';
        $lines[] = '7 - voltar';
        $lines[] = '0 - menu principal';

        return implode("\n", $lines);
    }

    public function buildCardDetailsMenu(array $data): string
    {
        $lines = [
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
                'Nao encontrei faturas para este cartao.',
                '',
                '7 - voltar',
                '0 - menu principal',
            ]);
        }

        $lines = [
            'Faturas - ' . ($data['card_description'] ?? 'Cartao') . ' - pagina ' . $page . ':',
        ];

        foreach ($invoices as $index => $invoice) {
            $lines[] = ($index + 1) . ' - Vencimento: ' . $this->formatDate($invoice['pay_day'] ?? null);
            $lines[] = '  Fechamento: ' . $this->formatDate($invoice['closure_date'] ?? null);
            $lines[] = '  Total: ' . $this->money($invoice['total'] ?? 0);
            $lines[] = '  Em aberto: ' . $this->money($invoice['open_total'] ?? 0);
            $lines[] = '  Status: ' . ($invoice['status'] ?? '-');
        }

        $lines[] = '';

        if ($hasPrevious) {
            $lines[] = '5 - anteriores';
        }

        if ($hasMore) {
            $lines[] = '6 - proximas';
        }

        $lines[] = 'Escolha 1 a 4 para abrir uma fatura.';
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
                'Escolha 1 a 4 para abrir um cartao, ou use:',
                '5 - anteriores',
                '6 - proximos',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_DETAILS => implode("\n", [
                'Opcao invalida para este submenu.',
                '1 - ver faturas',
                '2 - pagar fatura atual',
                '7 - voltar',
                '0 - menu principal',
            ]),
            ConversationSession::STATE_CARD_INVOICES => implode("\n", [
                'Opcao invalida para este submenu.',
                'Escolha 1 a 4 para abrir uma fatura, ou use:',
                '5 - anteriores',
                '6 - proximas',
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
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_METHOD => implode("\n", [
                'Opcao invalida para este passo.',
                'Escolha uma forma de pagamento listada ou use:',
                '7 - voltar',
                '0 - cancelar',
            ]),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CATEGORY => implode("\n", [
                'Opcao invalida para este passo.',
                'Escolha uma categoria listada ou use:',
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
