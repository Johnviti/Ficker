<?php

namespace App\Services\Telegram;

class TelegramLinkReplyBuilder
{
    public function buildForResolution(array $resolution): string
    {
        return match ($resolution['status'] ?? 'not_a_code') {
            'not_found' => 'Nao encontrei esse codigo de vinculacao. Gere um novo codigo no app e tente novamente.',
            'expired' => 'Esse codigo expirou. Gere um novo codigo no app do Ficker e envie novamente.',
            'already_used' => 'Esse codigo ja foi utilizado. Gere um novo codigo no app do Ficker.',
            default => 'Envie aqui o codigo de vinculacao gerado no Ficker para conectar sua conta ao Telegram.',
        };
    }

    public function buildForLinkResult(array $result): string
    {
        return match ($result['status'] ?? 'invalid_payload') {
            'linked' => 'Conta conectada com sucesso ao Telegram. Em breve voce podera consultar suas informacoes por aqui.',
            'chat_already_linked_to_other_user' => 'Este chat ja esta vinculado a outra conta do Ficker. Revogue o vinculo anterior antes de tentar novamente.',
            'telegram_user_already_linked_to_other_user' => 'Este usuario do Telegram ja esta vinculado a outra conta do Ficker. Revogue o vinculo anterior antes de tentar novamente.',
            default => 'Nao consegui concluir a vinculacao agora. Tente gerar um novo codigo no app e enviar novamente.',
        };
    }
}
