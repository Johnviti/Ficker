<?php

namespace App\Services\Telegram;

class TelegramCategoryReplyBuilder
{
    public function buildTypePrompt(): string
    {
        return implode("\n", [
            'Nova categoria',
            'Passo 1',
            'Escolha o tipo:',
            '1 - categoria de entrada',
            '2 - categoria de saida',
            '',
            '7 - voltar',
            '0 - cancelar',
        ]);
    }

    public function buildDescriptionPrompt(): string
    {
        return implode("\n", [
            'Nova categoria',
            'Passo 2',
            'Digite a descricao da categoria.',
            'Exemplo: Saude',
            '',
            '7 - voltar',
            '0 - cancelar',
        ]);
    }

    public function buildConfirmationPrompt(array $draft): string
    {
        return implode("\n", [
            'Confirmar categoria',
            'Tipo: ' . $this->typeLabel((int) ($draft['type_id'] ?? 0)),
            'Descricao: ' . ($draft['category_description'] ?? '-'),
            '',
            '1 - confirmar',
            '2 - cancelar',
            '7 - voltar',
            '0 - cancelar',
        ]);
    }

    public function buildValidationError(string $message, string $prompt): string
    {
        return implode("\n", [
            $message,
            '',
            $prompt,
        ]);
    }

    public function buildCreatedSuccess(array $result): string
    {
        return implode("\n", [
            'Categoria criada com sucesso.',
            'Descricao: ' . ($result['category']->category_description ?? '-'),
            'Tipo: ' . $this->typeLabel((int) ($result['category']->type_id ?? 0)),
            '',
            '0 - menu principal',
        ]);
    }

    public function buildCancelled(): string
    {
        return implode("\n", [
            'Criacao de categoria cancelada.',
            '',
            '0 - menu principal',
        ]);
    }

    private function typeLabel(int $typeId): string
    {
        return match ($typeId) {
            1 => 'Entrada',
            2 => 'Saida',
            3 => 'Cartao de credito',
            default => 'Nao definido',
        };
    }
}
