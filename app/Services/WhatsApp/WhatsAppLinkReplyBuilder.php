<?php

namespace App\Services\WhatsApp;

class WhatsAppLinkReplyBuilder
{
    public function build(array $linkResult): ?string
    {
        return match ($linkResult['status'] ?? null) {
            'linked' => 'Seu WhatsApp foi vinculado com sucesso a sua conta do Ficker.',
            'code_not_found' => 'Codigo de vinculacao invalido. Gere um novo codigo no app e envie novamente aqui.',
            'code_already_used' => 'Esse codigo ja foi utilizado. Gere um novo codigo no app para continuar.',
            'code_expired' => 'Esse codigo expirou. Gere um novo codigo no app e envie novamente aqui.',
            'phone_already_linked_to_another_user' => 'Este numero ja esta vinculado a outra conta e nao pode ser reutilizado.',
            'invalid_phone' => 'Nao foi possivel identificar o numero deste WhatsApp. Tente novamente mais tarde.',
            default => null,
        };
    }
}
