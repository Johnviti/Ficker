<?php

namespace App\Services\Telegram;

use App\Exceptions\TransactionCreationException;
use App\Models\ConversationSession;
use App\Services\Transactions\TransactionCreationService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class TelegramTransactionFlowService
{
    public function __construct(
        private readonly TelegramLookupService $lookupService,
        private readonly TelegramTransactionReplyBuilder $replyBuilder,
        private readonly TransactionCreationService $transactionCreationService
    ) {
    }

    public function isWizardState(?string $state): bool
    {
        return is_string($state) && str_starts_with($state, 'transaction_');
    }

    public function startIncomeFlow(ConversationSession $session, int $userId): array
    {
        $this->updateSession($session, ConversationSession::STATE_TRANSACTION_INCOME_VALUE, [
            ConversationSession::CONTEXT_PREVIOUS_STATE => $session->state ?: ConversationSession::STATE_MAIN_MENU,
            ConversationSession::CONTEXT_FLOW => 'income',
            ConversationSession::CONTEXT_DRAFT => [
                'type_id' => 1,
            ],
            ConversationSession::CONTEXT_STEP_HISTORY => [],
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildValuePrompt('income'),
        ];
    }

    public function startExpenseFlow(ConversationSession $session, int $userId): array
    {
        $this->updateSession($session, ConversationSession::STATE_TRANSACTION_EXPENSE_VALUE, [
            ConversationSession::CONTEXT_PREVIOUS_STATE => $session->state ?: ConversationSession::STATE_MAIN_MENU,
            ConversationSession::CONTEXT_FLOW => 'expense',
            ConversationSession::CONTEXT_DRAFT => [
                'type_id' => 2,
            ],
            ConversationSession::CONTEXT_STEP_HISTORY => [],
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildValuePrompt('expense'),
        ];
    }

    public function handleInput(ConversationSession $session, int $userId, string $text): array
    {
        $trimmedText = trim($text);

        if ($trimmedText === '0') {
            return $this->cancel($session, $userId);
        }

        if ($trimmedText === '7') {
            return $this->goBack($session, $userId);
        }

        return match ($session->state) {
            ConversationSession::STATE_TRANSACTION_INCOME_VALUE,
            ConversationSession::STATE_TRANSACTION_EXPENSE_VALUE => $this->handleValueStep($session, $userId, $trimmedText),
            ConversationSession::STATE_TRANSACTION_INCOME_DESCRIPTION,
            ConversationSession::STATE_TRANSACTION_EXPENSE_DESCRIPTION => $this->handleDescriptionStep($session, $userId, $trimmedText),
            ConversationSession::STATE_TRANSACTION_INCOME_CATEGORY,
            ConversationSession::STATE_TRANSACTION_EXPENSE_CATEGORY => $this->handleCategoryStep($session, $userId, $trimmedText),
            ConversationSession::STATE_TRANSACTION_INCOME_DATE,
            ConversationSession::STATE_TRANSACTION_EXPENSE_DATE => $this->handleDateStep($session, $userId, $trimmedText),
            ConversationSession::STATE_TRANSACTION_EXPENSE_PAYMENT_METHOD => $this->handlePaymentMethodStep($session, $userId, $trimmedText),
            ConversationSession::STATE_TRANSACTION_EXPENSE_CARD => $this->handleCardStep($session, $userId, $trimmedText),
            ConversationSession::STATE_TRANSACTION_EXPENSE_INSTALLMENTS => $this->handleInstallmentsStep($session, $userId, $trimmedText),
            ConversationSession::STATE_TRANSACTION_INCOME_CONFIRM,
            ConversationSession::STATE_TRANSACTION_EXPENSE_CONFIRM => $this->handleConfirmationStep($session, $userId, $trimmedText),
            default => $this->cancel($session, $userId),
        };
    }

    public function cancel(ConversationSession $session, int $userId): array
    {
        $flow = $session->context(ConversationSession::CONTEXT_FLOW, 'expense');
        $this->updateSession($session, ConversationSession::STATE_MAIN_MENU, [], $userId);

        return [
            'status' => 'cancelled',
            'flow' => $flow,
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
            'message' => $this->promptForState($session->fresh(), $userId),
        ];
    }

    private function handleValueStep(ConversationSession $session, int $userId, string $text): array
    {
        $parsed = $this->parseMoney($text);

        if (!$parsed['valid']) {
            return $this->validationError($parsed['message'], $this->promptForState($session, $userId));
        }

        $flow = $session->context(ConversationSession::CONTEXT_FLOW);
        $nextState = $flow === 'income'
            ? ConversationSession::STATE_TRANSACTION_INCOME_DESCRIPTION
            : ConversationSession::STATE_TRANSACTION_EXPENSE_DESCRIPTION;

        $this->transition($session, $nextState, [
            'transaction_value' => $parsed['value'],
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildDescriptionPrompt(),
        ];
    }

    private function handleDescriptionStep(ConversationSession $session, int $userId, string $text): array
    {
        $description = trim($text);

        if ($description === '' || mb_strlen($description) > 50) {
            return $this->validationError(
                'Informe uma descricao com ate 50 caracteres.',
                $this->replyBuilder->buildDescriptionPrompt()
            );
        }

        $flow = $session->context(ConversationSession::CONTEXT_FLOW);
        $typeId = $flow === 'income' ? 1 : 2;
        $categories = $this->lookupService->getCategoriesByType($userId, $typeId);
        $nextState = $flow === 'income'
            ? ConversationSession::STATE_TRANSACTION_INCOME_CATEGORY
            : ConversationSession::STATE_TRANSACTION_EXPENSE_CATEGORY;

        $this->transition($session, $nextState, [
            'transaction_description' => $description,
            'category_options' => $categories,
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildCategoryPrompt($flow, $categories),
        ];
    }

    private function handleCategoryStep(ConversationSession $session, int $userId, string $text): array
    {
        $options = $session->context(ConversationSession::CONTEXT_DRAFT . '.category_options', []);
        $selected = $options[$text] ?? null;

        if (is_null($selected)) {
            return $this->validationError(
                'Escolha uma das categorias listadas.',
                $this->promptForState($session, $userId)
            );
        }

        $flow = $session->context(ConversationSession::CONTEXT_FLOW);
        $nextState = $flow === 'income'
            ? ConversationSession::STATE_TRANSACTION_INCOME_DATE
            : ConversationSession::STATE_TRANSACTION_EXPENSE_DATE;

        $this->transition($session, $nextState, [
            'category_id' => $selected['id'],
            'resolved_category_description' => $selected['description'],
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildDatePrompt(),
        ];
    }

    private function handleDateStep(ConversationSession $session, int $userId, string $text): array
    {
        $parsed = $this->parseDate($text);

        if (!$parsed['valid']) {
            return $this->validationError($parsed['message'], $this->replyBuilder->buildDatePrompt());
        }

        $flow = $session->context(ConversationSession::CONTEXT_FLOW);

        if ($flow === 'income') {
            $this->transition($session, ConversationSession::STATE_TRANSACTION_INCOME_CONFIRM, [
                'date' => $parsed['value'],
            ], $userId);

            return [
                'status' => 'in_progress',
                'message' => $this->replyBuilder->buildConfirmationPrompt(
                    $session->fresh()->context(ConversationSession::CONTEXT_DRAFT, [])
                ),
            ];
        }

        $paymentMethods = $this->lookupService->getExpensePaymentMethods();
        $this->transition($session, ConversationSession::STATE_TRANSACTION_EXPENSE_PAYMENT_METHOD, [
            'date' => $parsed['value'],
            'payment_method_options' => $paymentMethods,
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildPaymentMethodPrompt($paymentMethods),
        ];
    }

    private function handlePaymentMethodStep(ConversationSession $session, int $userId, string $text): array
    {
        $options = $session->context(ConversationSession::CONTEXT_DRAFT . '.payment_method_options', []);
        $selected = $options[$text] ?? null;

        if (is_null($selected)) {
            return $this->validationError(
                'Escolha uma forma de pagamento listada.',
                $this->promptForState($session, $userId)
            );
        }

        if ((int) $selected['id'] !== 4) {
            $this->transition($session, ConversationSession::STATE_TRANSACTION_EXPENSE_CONFIRM, [
                'payment_method_id' => $selected['id'],
                'resolved_payment_method_description' => $selected['description'],
                'card_id' => null,
                'installments' => null,
                'resolved_card_description' => null,
            ], $userId);

            return [
                'status' => 'in_progress',
                'message' => $this->replyBuilder->buildConfirmationPrompt(
                    $session->fresh()->context(ConversationSession::CONTEXT_DRAFT, [])
                ),
            ];
        }

        $cards = $this->lookupService->getCards($userId);
        $this->transition($session, ConversationSession::STATE_TRANSACTION_EXPENSE_CARD, [
            'payment_method_id' => $selected['id'],
            'resolved_payment_method_description' => $selected['description'],
            'card_options' => $cards,
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildCardPrompt($cards),
        ];
    }

    private function handleCardStep(ConversationSession $session, int $userId, string $text): array
    {
        $options = $session->context(ConversationSession::CONTEXT_DRAFT . '.card_options', []);
        $selected = $options[$text] ?? null;

        if (is_null($selected)) {
            return $this->validationError(
                'Escolha um cartao listado.',
                $this->promptForState($session, $userId)
            );
        }

        $this->transition($session, ConversationSession::STATE_TRANSACTION_EXPENSE_INSTALLMENTS, [
            'card_id' => $selected['id'],
            'resolved_card_description' => $selected['description'],
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildInstallmentsPrompt(),
        ];
    }

    private function handleInstallmentsStep(ConversationSession $session, int $userId, string $text): array
    {
        if (!ctype_digit($text) || (int) $text < 1) {
            return $this->validationError(
                'Digite um numero inteiro de parcelas maior que zero.',
                $this->replyBuilder->buildInstallmentsPrompt()
            );
        }

        $this->transition($session, ConversationSession::STATE_TRANSACTION_EXPENSE_CONFIRM, [
            'installments' => (int) $text,
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

        $draft = $this->sanitizeDraftForCreation($session->context(ConversationSession::CONTEXT_DRAFT, []));

        try {
            $result = $this->transactionCreationService->create($userId, $draft);
        } catch (ValidationException $e) {
            return $this->validationError(
                'Nao consegui criar a transacao com os dados informados.',
                $this->replyBuilder->buildConfirmationPrompt($session->context(ConversationSession::CONTEXT_DRAFT, []))
            );
        } catch (TransactionCreationException $e) {
            return $this->validationError(
                $e->getMessage(),
                $this->replyBuilder->buildConfirmationPrompt($session->context(ConversationSession::CONTEXT_DRAFT, []))
            );
        }

        $this->updateSession($session, ConversationSession::STATE_MAIN_MENU, [], $userId);

        return [
            'status' => 'created',
            'message' => $this->replyBuilder->buildCreatedSuccess($result),
            'result' => $result,
            'flow' => $session->context(ConversationSession::CONTEXT_FLOW, 'expense'),
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

    private function promptForState(ConversationSession $session, int $userId): string
    {
        return match ($session->state) {
            ConversationSession::STATE_TRANSACTION_INCOME_VALUE => $this->replyBuilder->buildValuePrompt('income'),
            ConversationSession::STATE_TRANSACTION_EXPENSE_VALUE => $this->replyBuilder->buildValuePrompt('expense'),
            ConversationSession::STATE_TRANSACTION_INCOME_DESCRIPTION,
            ConversationSession::STATE_TRANSACTION_EXPENSE_DESCRIPTION => $this->replyBuilder->buildDescriptionPrompt(),
            ConversationSession::STATE_TRANSACTION_INCOME_CATEGORY => $this->replyBuilder->buildCategoryPrompt(
                'income',
                $this->lookupService->getCategoriesByType($userId, 1)
            ),
            ConversationSession::STATE_TRANSACTION_EXPENSE_CATEGORY => $this->replyBuilder->buildCategoryPrompt(
                'expense',
                $this->lookupService->getCategoriesByType($userId, 2)
            ),
            ConversationSession::STATE_TRANSACTION_INCOME_DATE,
            ConversationSession::STATE_TRANSACTION_EXPENSE_DATE => $this->replyBuilder->buildDatePrompt(),
            ConversationSession::STATE_TRANSACTION_EXPENSE_PAYMENT_METHOD => $this->replyBuilder->buildPaymentMethodPrompt(
                $session->context(ConversationSession::CONTEXT_DRAFT . '.payment_method_options', $this->lookupService->getExpensePaymentMethods())
            ),
            ConversationSession::STATE_TRANSACTION_EXPENSE_CARD => $this->replyBuilder->buildCardPrompt(
                $session->context(ConversationSession::CONTEXT_DRAFT . '.card_options', $this->lookupService->getCards($userId))
            ),
            ConversationSession::STATE_TRANSACTION_EXPENSE_INSTALLMENTS => $this->replyBuilder->buildInstallmentsPrompt(),
            ConversationSession::STATE_TRANSACTION_INCOME_CONFIRM,
            ConversationSession::STATE_TRANSACTION_EXPENSE_CONFIRM => $this->replyBuilder->buildConfirmationPrompt(
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

    private function parseMoney(string $text): array
    {
        $normalized = str_replace(['R$', ' '], '', trim($text));

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (!is_numeric($normalized) || (float) $normalized <= 0) {
            return [
                'valid' => false,
                'message' => 'Informe um valor numerico maior que zero.',
            ];
        }

        return [
            'valid' => true,
            'value' => (float) $normalized,
        ];
    }

    private function parseDate(string $text): array
    {
        try {
            $date = Carbon::createFromFormat('d/m/Y', $text)->startOfDay();
        } catch (\Throwable) {
            return [
                'valid' => false,
                'message' => 'Informe a data no formato DD/MM/AAAA.',
            ];
        }

        if ($date->format('d/m/Y') !== $text) {
            return [
                'valid' => false,
                'message' => 'Informe a data no formato DD/MM/AAAA.',
            ];
        }

        if ($date->gt(now()->startOfDay())) {
            return [
                'valid' => false,
                'message' => 'A data deve ser anterior ou igual a hoje.',
            ];
        }

        return [
            'valid' => true,
            'value' => $date->toDateString(),
        ];
    }

    private function sanitizeDraftForCreation(array $draft): array
    {
        return array_filter([
            'transaction_description' => $draft['transaction_description'] ?? null,
            'category_id' => $draft['category_id'] ?? null,
            'category_description' => $draft['category_description'] ?? null,
            'date' => $draft['date'] ?? null,
            'type_id' => $draft['type_id'] ?? null,
            'transaction_value' => $draft['transaction_value'] ?? null,
            'payment_method_id' => $draft['payment_method_id'] ?? null,
            'installments' => $draft['installments'] ?? null,
            'card_id' => $draft['card_id'] ?? null,
        ], static fn ($value) => !is_null($value));
    }
}
