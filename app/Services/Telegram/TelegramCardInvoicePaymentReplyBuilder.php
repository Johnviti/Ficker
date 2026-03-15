<?php

namespace App\Services\Telegram;

class TelegramCardInvoicePaymentReplyBuilder
{
    public function buildUnavailable(string $message, array $cardContext = []): string
    {
        $lines = [
            $message,
            'Cartao: ' . ($cardContext['selected_card_description'] ?? 'Cartao'),
        ];

        if (!empty($cardContext['selected_card_closure_date'])) {
            $lines[] = 'Fechamento: ' . $this->formatDate($cardContext['selected_card_closure_date']);
        }

        if (!empty($cardContext['selected_card_pay_day'])) {
            $lines[] = 'Vencimento: ' . $this->formatDate($cardContext['selected_card_pay_day']);
        }

        $lines[] = '';
        $lines[] = '7 - voltar';
        $lines[] = '0 - menu principal';

        return implode("\n", $lines);
    }

    public function buildPaymentMethodPrompt(array $methods, array $cardContext = []): string
    {
        $lines = [
            'Pagamento de fatura.',
            'Cartao: ' . ($cardContext['selected_card_description'] ?? 'Cartao'),
            'Valor da fatura: ' . $this->money($cardContext['selected_card_invoice_total'] ?? 0),
            'Vencimento: ' . $this->formatDate($cardContext['selected_card_pay_day'] ?? null),
            '',
            'Escolha a forma de pagamento:',
        ];

        foreach ($methods as $option => $method) {
            $lines[] = $option . ' - ' . ($method['description'] ?? 'Metodo');
        }

        $lines[] = '';
        $lines[] = '7 - voltar';
        $lines[] = '0 - cancelar';

        return implode("\n", $lines);
    }

    public function buildCategoryPrompt(array $categories, array $cardContext = []): string
    {
        $lines = [
            'Escolha a categoria da saida:',
        ];

        foreach ($categories as $option => $category) {
            $lines[] = $option . ' - ' . ($category['description'] ?? 'Categoria');
        }

        $lines[] = '';
        $lines[] = 'Cartao: ' . ($cardContext['selected_card_description'] ?? 'Cartao');
        $lines[] = 'Valor da fatura: ' . $this->money($cardContext['selected_card_invoice_total'] ?? 0);
        $lines[] = '';
        $lines[] = '7 - voltar';
        $lines[] = '0 - cancelar';

        return implode("\n", $lines);
    }

    public function buildConfirmationPrompt(array $draft, array $cardContext = []): string
    {
        return implode("\n", [
            'Confirme o pagamento da fatura:',
            'Cartao: ' . ($cardContext['selected_card_description'] ?? 'Cartao'),
            'Valor: ' . $this->money($cardContext['selected_card_invoice_total'] ?? 0),
            'Vencimento: ' . $this->formatDate($cardContext['selected_card_pay_day'] ?? null),
            'Pagamento: ' . ($draft['resolved_payment_method_description'] ?? '-'),
            'Categoria: ' . ($draft['resolved_category_description'] ?? 'Pagamento de fatura'),
            '',
            '1 - confirmar',
            '2 - cancelar',
            '7 - voltar',
            '0 - cancelar',
        ]);
    }

    public function buildCreatedSuccess(array $result): string
    {
        return implode("\n", [
            'Fatura paga com sucesso.',
            'Cartao: ' . ($result['card']->card_description ?? 'Cartao'),
            'Valor: ' . $this->money($result['invoice_value'] ?? 0),
            'Vencimento: ' . $this->formatDate($result['pay_day'] ?? null),
            'Transacao: ' . ($result['payment_transaction']->transaction_description ?? 'Pagamento de fatura'),
            '',
            '0 - menu principal',
        ]);
    }

    public function buildCancelled(): string
    {
        return implode("\n", [
            'Pagamento de fatura cancelado.',
            '',
            '0 - menu principal',
        ]);
    }

    public function buildValidationError(string $message, string $prompt): string
    {
        return $message . "\n\n" . $prompt;
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
