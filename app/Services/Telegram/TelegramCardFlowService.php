<?php

namespace App\Services\Telegram;

use App\Models\ConversationSession;
use App\Services\Cards\CardCreationService;
use Illuminate\Validation\ValidationException;

class TelegramCardFlowService
{
    public function __construct(
        private readonly TelegramLookupService $lookupService,
        private readonly TelegramCardReplyBuilder $replyBuilder,
        private readonly CardCreationService $cardCreationService
    ) {
    }

    public function isWizardState(?string $state): bool
    {
        return in_array($state, [
            ConversationSession::STATE_CARD_CREATE_DESCRIPTION,
            ConversationSession::STATE_CARD_CREATE_FLAG,
            ConversationSession::STATE_CARD_CREATE_CLOSURE,
            ConversationSession::STATE_CARD_CREATE_EXPIRATION,
            ConversationSession::STATE_CARD_CREATE_CONFIRM,
        ], true);
    }

    public function start(ConversationSession $session, int $userId): array
    {
        $this->updateSession($session, ConversationSession::STATE_CARD_CREATE_DESCRIPTION, [
            ConversationSession::CONTEXT_PREVIOUS_STATE => $session->state ?: ConversationSession::STATE_MAIN_MENU,
            ConversationSession::CONTEXT_FLOW => 'card_create',
            ConversationSession::CONTEXT_DRAFT => [],
            ConversationSession::CONTEXT_STEP_HISTORY => [],
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildDescriptionPrompt(),
        ];
    }

    public function handleInput(ConversationSession $session, int $userId, string $text): array
    {
        $trimmed = trim($text);

        if ($trimmed === '0') {
            return $this->cancel($session, $userId);
        }

        if ($trimmed === '7') {
            return $this->goBack($session, $userId);
        }

        if (strtolower($trimmed) === 'ajuda') {
            return [
                'status' => 'in_progress',
                'message' => $this->promptForState($session),
            ];
        }

        return match ($session->state) {
            ConversationSession::STATE_CARD_CREATE_DESCRIPTION => $this->handleDescriptionStep($session, $userId, $trimmed),
            ConversationSession::STATE_CARD_CREATE_FLAG => $this->handleFlagStep($session, $userId, $trimmed),
            ConversationSession::STATE_CARD_CREATE_CLOSURE => $this->handleClosureStep($session, $userId, $trimmed),
            ConversationSession::STATE_CARD_CREATE_EXPIRATION => $this->handleExpirationStep($session, $userId, $trimmed),
            ConversationSession::STATE_CARD_CREATE_CONFIRM => $this->handleConfirmationStep($session, $userId, $trimmed),
            default => $this->cancel($session, $userId),
        };
    }

    public function cancel(ConversationSession $session, int $userId): array
    {
        $this->updateSession($session, ConversationSession::STATE_MAIN_MENU, [], $userId);

        return [
            'status' => 'cancelled',
            'flow' => 'card_create',
            'message' => $this->replyBuilder->buildCancelled(),
        ];
    }

    public function goBack(ConversationSession $session, int $userId): array
    {
        $history = $session->context(ConversationSession::CONTEXT_STEP_HISTORY, []);

        if ($history === []) {
            return $this->cancel($session, $userId);
        }

        $targetState = array_pop($history);
        $context = $session->context_json ?? [];
        $context[ConversationSession::CONTEXT_STEP_HISTORY] = array_values($history);
        $this->updateSession($session, $targetState, $context, $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->promptForState($session->fresh()),
        ];
    }

    private function handleDescriptionStep(ConversationSession $session, int $userId, string $text): array
    {
        if ($text === '' || mb_strlen($text) < 2 || mb_strlen($text) > 50) {
            return $this->validationError(
                'Informe uma descricao entre 2 e 50 caracteres.',
                $this->replyBuilder->buildDescriptionPrompt()
            );
        }

        $flags = $this->lookupService->getFlags();
        $this->transition($session, ConversationSession::STATE_CARD_CREATE_FLAG, [
            'card_description' => $text,
            'flag_options' => $flags,
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildFlagPrompt($flags),
        ];
    }

    private function handleFlagStep(ConversationSession $session, int $userId, string $text): array
    {
        $options = $session->context(ConversationSession::CONTEXT_DRAFT . '.flag_options', []);
        $selected = $options[$text] ?? null;

        if (is_null($selected)) {
            return $this->validationError(
                'Escolha uma das bandeiras listadas.',
                $this->promptForState($session)
            );
        }

        $this->transition($session, ConversationSession::STATE_CARD_CREATE_CLOSURE, [
            'flag_id' => $selected['id'],
            'resolved_flag_description' => $selected['description'],
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildClosurePrompt(),
        ];
    }

    private function handleClosureStep(ConversationSession $session, int $userId, string $text): array
    {
        if (!$this->isValidDay($text)) {
            return $this->validationError(
                'Digite um dia de fechamento entre 1 e 31.',
                $this->replyBuilder->buildClosurePrompt()
            );
        }

        $this->transition($session, ConversationSession::STATE_CARD_CREATE_EXPIRATION, [
            'closure' => (int) $text,
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildExpirationPrompt(),
        ];
    }

    private function handleExpirationStep(ConversationSession $session, int $userId, string $text): array
    {
        if (!$this->isValidDay($text)) {
            return $this->validationError(
                'Digite um dia de vencimento entre 1 e 31.',
                $this->replyBuilder->buildExpirationPrompt()
            );
        }

        if ((int) $text === (int) $session->context(ConversationSession::CONTEXT_DRAFT . '.closure')) {
            return $this->validationError(
                'O vencimento nao pode ser no mesmo dia do fechamento.',
                $this->replyBuilder->buildExpirationPrompt()
            );
        }

        $this->transition($session, ConversationSession::STATE_CARD_CREATE_CONFIRM, [
            'expiration' => (int) $text,
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildConfirmationPrompt(
                $session->fresh()->context(ConversationSession::CONTEXT_DRAFT, [])
            ),
        ];
    }

    private function handleConfirmationStep(ConversationSession $session, int $userId, string $text): array
    {
        if ($text === '2') {
            return $this->cancel($session, $userId);
        }

        if ($text !== '1') {
            return $this->validationError(
                'Escolha 1 para confirmar ou 2 para cancelar.',
                $this->replyBuilder->buildConfirmationPrompt($session->context(ConversationSession::CONTEXT_DRAFT, []))
            );
        }

        try {
            $result = $this->cardCreationService->create($userId, $session->context(ConversationSession::CONTEXT_DRAFT, []));
        } catch (ValidationException $e) {
            return $this->validationError(
                'Nao consegui criar o cartao com os dados informados.',
                $this->replyBuilder->buildConfirmationPrompt($session->context(ConversationSession::CONTEXT_DRAFT, []))
            );
        }

        $this->updateSession($session, ConversationSession::STATE_MAIN_MENU, [], $userId);

        return [
            'status' => 'created',
            'flow' => 'card_create',
            'message' => $this->replyBuilder->buildCreatedSuccess($result),
            'result' => $result,
        ];
    }

    private function transition(ConversationSession $session, string $nextState, array $draftUpdates, int $userId): void
    {
        $context = $session->context_json ?? [];
        $draft = $context[ConversationSession::CONTEXT_DRAFT] ?? [];
        $history = $context[ConversationSession::CONTEXT_STEP_HISTORY] ?? [];

        $history[] = $session->state;
        $context[ConversationSession::CONTEXT_DRAFT] = array_merge($draft, $draftUpdates);
        $context[ConversationSession::CONTEXT_STEP_HISTORY] = array_values($history);

        $this->updateSession($session, $nextState, $context, $userId);
    }

    private function updateSession(ConversationSession $session, string $state, array $context, int $userId): void
    {
        $session->setState($state, $context);
        $session->touchMessage($userId);
    }

    private function promptForState(ConversationSession $session): string
    {
        return match ($session->state) {
            ConversationSession::STATE_CARD_CREATE_DESCRIPTION => $this->replyBuilder->buildDescriptionPrompt(),
            ConversationSession::STATE_CARD_CREATE_FLAG => $this->replyBuilder->buildFlagPrompt(
                $session->context(ConversationSession::CONTEXT_DRAFT . '.flag_options', [])
            ),
            ConversationSession::STATE_CARD_CREATE_CLOSURE => $this->replyBuilder->buildClosurePrompt(),
            ConversationSession::STATE_CARD_CREATE_EXPIRATION => $this->replyBuilder->buildExpirationPrompt(),
            ConversationSession::STATE_CARD_CREATE_CONFIRM => $this->replyBuilder->buildConfirmationPrompt(
                $session->context(ConversationSession::CONTEXT_DRAFT, [])
            ),
            default => $this->replyBuilder->buildCancelled(),
        };
    }

    private function validationError(string $message, string $prompt): array
    {
        return [
            'status' => 'validation_error',
            'message' => $this->replyBuilder->buildValidationError($message, $prompt),
        ];
    }

    private function isValidDay(string $text): bool
    {
        return ctype_digit($text) && (int) $text >= 1 && (int) $text <= 31;
    }
}
