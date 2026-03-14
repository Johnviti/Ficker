<?php

namespace App\Services\Telegram;

use App\Models\ConversationSession;

class TelegramConversationService
{
    public function resolveSession(array $normalizedPayload, ?int $userId = null): ConversationSession
    {
        $chatId = (string) ($normalizedPayload['telegram_chat_id'] ?? '');

        $session = ConversationSession::firstOrCreate(
            [
                'channel' => 'telegram',
                'external_chat_id' => $chatId,
            ],
            [
                'user_id' => $userId,
                'state' => ConversationSession::STATE_MAIN_MENU,
                'context_json' => [],
                'last_message_at' => now(),
            ]
        );

        $session->touchMessage($userId);

        return $session->fresh();
    }

    public function goToMainMenu(ConversationSession $session, ?int $userId = null): ConversationSession
    {
        $session->setState(ConversationSession::STATE_MAIN_MENU, []);
        $session->touchMessage($userId);

        return $session->fresh();
    }

    public function goToState(
        ConversationSession $session,
        string $state,
        array $context = [],
        ?int $userId = null,
        bool $rememberPrevious = true
    ): ConversationSession {
        $currentState = $session->state ?: ConversationSession::STATE_MAIN_MENU;
        $nextContext = $context;

        if ($rememberPrevious && $state !== $currentState) {
            $nextContext[ConversationSession::CONTEXT_PREVIOUS_STATE] = $currentState;
        } elseif (!$rememberPrevious) {
            $nextContext[ConversationSession::CONTEXT_PREVIOUS_STATE] = $session->context(
                ConversationSession::CONTEXT_PREVIOUS_STATE,
                ConversationSession::STATE_MAIN_MENU
            );
        }

        $session->setState($state, $nextContext);
        $session->touchMessage($userId);

        return $session->fresh();
    }

    public function goBack(ConversationSession $session, ?int $userId = null): ConversationSession
    {
        $previousState = (string) $session->context(
            ConversationSession::CONTEXT_PREVIOUS_STATE,
            ConversationSession::STATE_MAIN_MENU
        );

        return $this->goToState($session, $previousState, [], $userId);
    }
}
