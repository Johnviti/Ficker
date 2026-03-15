<?php

namespace App\Services\Telegram;

class TelegramTransactionReplyBuilder
{
    public function buildValuePrompt(string $flow): string
    {
        return implode("\n", [
            $flow === 'income' ? 'Nova entrada.' : 'Nova saida.',
            'Digite o valor usando apenas numeros.',
            'Exemplo: 125.90',
            '',
            '7 - voltar',
            '0 - cancelar',
        ]);
    }

    public function buildDescriptionPrompt(): string
    {
        return implode("\n", [
            'Digite uma descricao curta para a transacao.',
            'Exemplo: Mercado do mes',
            '',
            '7 - voltar',
            '0 - cancelar',
        ]);
    }

    public function buildCategoryPrompt(string $flow, array $categories): string
    {
        if ($categories === []) {
            return implode("\n", [
                'Nao encontrei categorias disponiveis para este tipo.',
                'Crie uma categoria no app e tente novamente.',
                '',
                '7 - voltar',
                '0 - cancelar',
            ]);
        }

        $lines = [
            $flow === 'income' ? 'Escolha a categoria da entrada:' : 'Escolha a categoria da saida:',
        ];

        foreach ($categories as $option => $category) {
            $lines[] = $option . ' - ' . ($category['description'] ?? 'Categoria');
        }

        $lines[] = '';
        $lines[] = '7 - voltar';
        $lines[] = '0 - cancelar';

        return implode("\n", $lines);
    }

    public function buildDatePrompt(): string
    {
        return implode("\n", [
            'Digite a data no formato DD/MM/AAAA.',
            'Exemplo: 14/03/2026',
            '',
            '7 - voltar',
            '0 - cancelar',
        ]);
    }

    public function buildPaymentMethodPrompt(array $paymentMethods): string
    {
        $lines = ['Escolha a forma de pagamento:'];

        foreach ($paymentMethods as $option => $paymentMethod) {
            $lines[] = $option . ' - ' . ($paymentMethod['description'] ?? 'Forma de pagamento');
        }

        $lines[] = '';
        $lines[] = '7 - voltar';
        $lines[] = '0 - cancelar';

        return implode("\n", $lines);
    }

    public function buildCardPrompt(array $cards): string
    {
        if ($cards === []) {
            return implode("\n", [
                'Nao encontrei cartoes cadastrados para sua conta.',
                'Cadastre um cartao no app ou escolha outra forma de pagamento.',
                '',
                '7 - voltar',
                '0 - cancelar',
            ]);
        }

        $lines = ['Escolha o cartao:'];

        foreach ($cards as $option => $card) {
            $lines[] = $option . ' - ' . ($card['description'] ?? 'Cartao');
        }

        $lines[] = '';
        $lines[] = '7 - voltar';
        $lines[] = '0 - cancelar';

        return implode("\n", $lines);
    }

    public function buildInstallmentsPrompt(): string
    {
        return implode("\n", [
            'Digite o numero de parcelas.',
            'Exemplo: 3',
            '',
            '7 - voltar',
            '0 - cancelar',
        ]);
    }

    public function buildConfirmationPrompt(array $draft): string
    {
        $lines = [
            'Confirme os dados da transacao:',
            'Tipo: ' . (($draft['type_id'] ?? 2) === 1 ? 'Entrada' : 'Saida'),
            'Valor: ' . $this->money($draft['transaction_value'] ?? 0),
            'Descricao: ' . ($draft['transaction_description'] ?? '-'),
            'Categoria: ' . ($draft['resolved_category_description'] ?? '-'),
            'Data: ' . $this->formatDate($draft['date'] ?? null),
        ];

        if (($draft['type_id'] ?? 2) === 2) {
            $lines[] = 'Pagamento: ' . ($draft['resolved_payment_method_description'] ?? '-');

            if ((int) ($draft['payment_method_id'] ?? 0) === 4) {
                $lines[] = 'Cartao: ' . ($draft['resolved_card_description'] ?? '-');
                $lines[] = 'Parcelas: ' . ($draft['installments'] ?? '-');
            }
        }

        $lines[] = '';
        $lines[] = '1 - confirmar';
        $lines[] = '2 - cancelar';
        $lines[] = '7 - voltar';
        $lines[] = '0 - cancelar';

        return implode("\n", $lines);
    }

    public function buildValidationError(string $message, string $prompt): string
    {
        return implode("\n", [
            $message,
            '',
            $prompt,
        ]);
    }

    public function buildCancelled(): string
    {
        return implode("\n", [
            'Fluxo cancelado.',
            '',
            '0 - menu principal',
            '1 - cartoes',
            '2 - transacoes',
            '3 - saldo geral',
            '4 - nova entrada',
            '5 - nova saida',
            '6 - nova categoria',
        ]);
    }

    public function buildCreatedSuccess(array $result): string
    {
        $transaction = $result['transaction'] ?? null;
        $installments = $result['installments'] ?? [];

        $lines = [
            'Transacao criada com sucesso.',
            'Descricao: ' . ($transaction?->transaction_description ?? '-'),
            'Valor: ' . $this->money($transaction?->transaction_value ?? 0),
            'Data: ' . $this->formatDate($transaction?->date ?? null),
        ];

        if ($installments !== []) {
            $lines[] = 'Parcelas: ' . count($installments);
        }

        $lines[] = '';
        $lines[] = '0 - menu principal';

        return implode("\n", $lines);
    }

    private function money(float|int|string|null $value): string
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
