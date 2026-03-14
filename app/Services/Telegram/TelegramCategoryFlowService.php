<?php

namespace App\Services\Telegram;

use App\Models\ConversationSession;
use App\Services\Categories\CategoryCreationService;
use Illuminate\Validation\ValidationException;

class TelegramCategoryFlowService
{
    public function __construct(
        private readonly TelegramCategoryReplyBuilder $replyBuilder,
        private readonly CategoryCreationService $categoryCreationService
    ) {
    }

    public function isWizardState(?string $state): bool
    {
        return in_array($state, [
            ConversationSession::STATE_CATEGORY_CREATE_TYPE,
            ConversationSession::STATE_CATEGORY_CREATE_DESCRIPTION,
            ConversationSession::STATE_CATEGORY_CREATE_CONFIRM,
        ], true);
    }

    public function start(ConversationSession $session, int $userId): array
    {
        $this->updateSession($session, ConversationSession::STATE_CATEGORY_CREATE_TYPE, [
            ConversationSession::CONTEXT_PREVIOUS_STATE => $session->state ?: ConversationSession::STATE_MAIN_MENU,
            ConversationSession::CONTEXT_FLOW => 'category_create',
            ConversationSession::CONTEXT_DRAFT => [],
            ConversationSession::CONTEXT_STEP_HISTORY => [],
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildTypePrompt(),
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
            ConversationSession::STATE_CATEGORY_CREATE_TYPE => $this->handleTypeStep($session, $userId, $trimmed),
            ConversationSession::STATE_CATEGORY_CREATE_DESCRIPTION => $this->handleDescriptionStep($session, $userId, $trimmed),
            ConversationSession::STATE_CATEGORY_CREATE_CONFIRM => $this->handleConfirmationStep($session, $userId, $trimmed),
            default => $this->cancel($session, $userId),
        };
    }

    public function cancel(ConversationSession $session, int $userId): array
    {
        $this->updateSession($session, ConversationSession::STATE_MAIN_MENU, [], $userId);

        return [
            'status' => 'cancelled',
            'flow' => 'category_create',
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

    private function handleTypeStep(ConversationSession $session, int $userId, string $text): array
    {
        if (!in_array($text, ['1', '2'], true)) {
            return $this->validationError('Escolha 1 para entrada ou 2 para saida.', $this->replyBuilder->buildTypePrompt());
        }

        $this->transition($session, ConversationSession::STATE_CATEGORY_CREATE_DESCRIPTION, [
            'type_id' => (int) $text,
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildDescriptionPrompt(),
        ];
    }

    private function handleDescriptionStep(ConversationSession $session, int $userId, string $text): array
    {
        $description = trim($text);

        if ($description === '' || mb_strlen($description) < 2 || mb_strlen($description) > 50) {
            return $this->validationError(
                'Informe uma descricao entre 2 e 50 caracteres.',
                $this->replyBuilder->buildDescriptionPrompt()
            );
        }

        $this->transition($session, ConversationSession::STATE_CATEGORY_CREATE_CONFIRM, [
            'category_description' => $description,
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

        $draft = $session->context(ConversationSession::CONTEXT_DRAFT, []);

        try {
            $result = $this->categoryCreationService->create($userId, [
                'category_description' => $draft['category_description'] ?? null,
                'type_id' => $draft['type_id'] ?? null,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError(
                'Nao consegui criar a categoria com os dados informados.',
                $this->replyBuilder->buildConfirmationPrompt($draft)
            );
        }

        $this->updateSession($session, ConversationSession::STATE_MAIN_MENU, [], $userId);

        return [
            'status' => 'created',
            'flow' => 'category_create',
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
            ConversationSession::STATE_CATEGORY_CREATE_TYPE => $this->replyBuilder->buildTypePrompt(),
            ConversationSession::STATE_CATEGORY_CREATE_DESCRIPTION => $this->replyBuilder->buildDescriptionPrompt(),
            ConversationSession::STATE_CATEGORY_CREATE_CONFIRM => $this->replyBuilder->buildConfirmationPrompt(
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
}
