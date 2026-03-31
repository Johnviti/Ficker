<?php

namespace App\Services\Telegram;

use App\Exceptions\CardInvoicePaymentException;
use App\Models\ConversationSession;
use App\Services\Cards\CardInvoicePaymentService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class TelegramCardInvoicePaymentFlowService
{
    public function __construct(
        private readonly TelegramLookupService $lookupService,
        private readonly TelegramCardInvoicePaymentReplyBuilder $replyBuilder,
        private readonly CardInvoicePaymentService $paymentService,
        private readonly TelegramCardsQueryService $cardsQueryService,
        private readonly TelegramMenuBuilder $menuBuilder
    ) {
    }

    public function isWizardState(?string $state): bool
    {
        return in_array($state, [
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_METHOD,
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CATEGORY,
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CONFIRM,
        ], true);
    }

    public function start(ConversationSession $session, int $userId): array
    {
        $selectedCardId = (int) $session->context(ConversationSession::CONTEXT_SELECTED_CARD_ID, 0);
        $latestCardDetails = $selectedCardId > 0
            ? $this->cardsQueryService->getCardDetails($userId, $selectedCardId)
            : [];

        $cardContext = $this->cardContextFromQueryResult($session, $latestCardDetails);

        if (($cardContext['selected_card_id'] ?? 0) <= 0 || empty($cardContext['selected_card_pay_day'])) {
            return [
                'status' => 'blocked',
                'message' => $this->replyBuilder->buildUnavailable(
                    'Nao ha fatura atual disponivel para pagamento neste cartao.',
                    $cardContext
                ),
            ];
        }

        $closureDate = $cardContext['selected_card_closure_date'] ?? null;

        if ($closureDate && Carbon::today()->lt(Carbon::parse($closureDate)->startOfDay())) {
            return [
                'status' => 'blocked',
                'message' => $this->replyBuilder->buildUnavailable(
                    'A fatura ainda nao fechou. Voce so pode pagar apos o fechamento.',
                    $cardContext
                ),
            ];
        }

        $methods = $this->lookupService->getInvoicePaymentMethods();
        $this->updateSession($session, ConversationSession::STATE_CARD_INVOICE_PAYMENT_METHOD, [
            ConversationSession::CONTEXT_PREVIOUS_STATE => ConversationSession::STATE_CARD_DETAILS,
            ConversationSession::CONTEXT_FLOW => 'card_invoice_payment',
            ConversationSession::CONTEXT_SELECTED_CARD_ID => $cardContext['selected_card_id'],
            ConversationSession::CONTEXT_SELECTED_CARD_DESCRIPTION => $cardContext['selected_card_description'],
            ConversationSession::CONTEXT_SELECTED_CARD_PAY_DAY => $cardContext['selected_card_pay_day'],
            ConversationSession::CONTEXT_SELECTED_CARD_CLOSURE_DATE => $cardContext['selected_card_closure_date'],
            ConversationSession::CONTEXT_SELECTED_CARD_INVOICE_TOTAL => $cardContext['selected_card_invoice_total'],
            ConversationSession::CONTEXT_PAGE => (int) $session->context(ConversationSession::CONTEXT_PAGE, 1),
            ConversationSession::CONTEXT_PER_PAGE => (int) $session->context(ConversationSession::CONTEXT_PER_PAGE, 5),
            ConversationSession::CONTEXT_PARENT_PAGE => $cardContext['parent_page'] ?? 1,
            ConversationSession::CONTEXT_DRAFT => [
                'payment_method_options' => $methods,
            ],
            ConversationSession::CONTEXT_STEP_HISTORY => [],
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildPaymentMethodPrompt($methods, $cardContext),
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
                'message' => $this->promptForState($session, $userId),
            ];
        }

        return match ($session->state) {
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_METHOD => $this->handlePaymentMethodStep($session, $userId, $trimmed),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CATEGORY => $this->handleCategoryStep($session, $userId, $trimmed),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CONFIRM => $this->handleConfirmationStep($session, $userId, $trimmed),
            default => $this->cancel($session, $userId),
        };
    }

    public function cancel(ConversationSession $session, int $userId): array
    {
        $this->updateSession($session, ConversationSession::STATE_MAIN_MENU, [], $userId);

        return [
            'status' => 'cancelled',
            'flow' => 'card_invoice_payment',
            'message' => $this->replyBuilder->buildCancelled(),
        ];
    }

    public function goBack(ConversationSession $session, int $userId): array
    {
        $history = $session->context(ConversationSession::CONTEXT_STEP_HISTORY, []);

        if ($history === []) {
            $queryResult = $this->cardsQueryService->getCardDetails(
                $userId,
                (int) $session->context(ConversationSession::CONTEXT_SELECTED_CARD_ID, 0)
            );
            $this->restoreCardDetailsState($session, $userId, $queryResult);

            return [
                'status' => 'in_progress',
                'message' => $this->menuBuilder->buildCardDetailsMenu($queryResult),
            ];
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

        $categories = $this->lookupService->getInvoicePaymentCategoryOptions($userId);
        $this->transition($session, ConversationSession::STATE_CARD_INVOICE_PAYMENT_CATEGORY, [
            'payment_method_id' => $selected['id'],
            'resolved_payment_method_description' => $selected['description'],
            'category_options' => $categories,
        ], $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildCategoryPrompt($categories, $this->cardContext($session->fresh())),
        ];
    }

    private function handleCategoryStep(ConversationSession $session, int $userId, string $text): array
    {
        $options = $session->context(ConversationSession::CONTEXT_DRAFT . '.category_options', []);
        $selected = $options[$text] ?? null;

        if (is_null($selected)) {
            return $this->validationError(
                'Escolha uma categoria listada.',
                $this->promptForState($session, $userId)
            );
        }

        $draftUpdates = [
            'resolved_category_description' => $selected['description'],
        ];

        if (($selected['mode'] ?? 'existing') === 'default') {
            $draftUpdates['category_id'] = null;
        } else {
            $draftUpdates['category_id'] = $selected['id'] ?? null;
        }

        $this->transition($session, ConversationSession::STATE_CARD_INVOICE_PAYMENT_CONFIRM, $draftUpdates, $userId);

        return [
            'status' => 'in_progress',
            'message' => $this->replyBuilder->buildConfirmationPrompt(
                $session->fresh()->context(ConversationSession::CONTEXT_DRAFT, []),
                $this->cardContext($session->fresh())
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
                $this->promptForState($session, $userId)
            );
        }

        $draft = $session->context(ConversationSession::CONTEXT_DRAFT, []);

        try {
            $result = $this->paymentService->payInvoiceByPayDay(
                $userId,
                (int) $session->context(ConversationSession::CONTEXT_SELECTED_CARD_ID, 0),
                (string) $session->context(ConversationSession::CONTEXT_SELECTED_CARD_PAY_DAY, ''),
                array_filter([
                    'payment_method_id' => $draft['payment_method_id'] ?? null,
                    'category_id' => $draft['category_id'] ?? null,
                ], static fn ($value) => !is_null($value))
            );
        } catch (ValidationException $e) {
            return $this->validationError(
                'Nao consegui pagar a fatura com os dados informados.',
                $this->promptForState($session, $userId)
            );
        } catch (CardInvoicePaymentException $e) {
            return $this->validationError(
                $e->getMessage(),
                $this->promptForState($session, $userId)
            );
        }

        $this->updateSession($session, ConversationSession::STATE_MAIN_MENU, [], $userId);

        return [
            'status' => 'created',
            'flow' => 'card_invoice_payment',
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

    private function restoreCardDetailsState(ConversationSession $session, int $userId, array $queryResult): void
    {
        $this->updateSession($session, ConversationSession::STATE_CARD_DETAILS, [
            ConversationSession::CONTEXT_PREVIOUS_STATE => ConversationSession::STATE_CARDS_SUMMARY,
            ConversationSession::CONTEXT_SELECTED_CARD_ID => $session->context(ConversationSession::CONTEXT_SELECTED_CARD_ID),
            ConversationSession::CONTEXT_SELECTED_CARD_DESCRIPTION => $session->context(ConversationSession::CONTEXT_SELECTED_CARD_DESCRIPTION),
            ConversationSession::CONTEXT_SELECTED_CARD_PAY_DAY => $queryResult['pay_day'] ?? $session->context(ConversationSession::CONTEXT_SELECTED_CARD_PAY_DAY),
            ConversationSession::CONTEXT_SELECTED_CARD_CLOSURE_DATE => $queryResult['closure_date'] ?? $session->context(ConversationSession::CONTEXT_SELECTED_CARD_CLOSURE_DATE),
            ConversationSession::CONTEXT_SELECTED_CARD_INVOICE_TOTAL => $queryResult['open_total'] ?? $queryResult['invoice_total'] ?? $session->context(ConversationSession::CONTEXT_SELECTED_CARD_INVOICE_TOTAL),
            ConversationSession::CONTEXT_PARENT_PAGE => $session->context(ConversationSession::CONTEXT_PARENT_PAGE, 1),
        ], $userId);
    }

    private function promptForState(ConversationSession $session, int $userId): string
    {
        return match ($session->state) {
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_METHOD => $this->replyBuilder->buildPaymentMethodPrompt(
                $session->context(ConversationSession::CONTEXT_DRAFT . '.payment_method_options', $this->lookupService->getInvoicePaymentMethods()),
                $this->cardContext($session)
            ),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CATEGORY => $this->replyBuilder->buildCategoryPrompt(
                $session->context(ConversationSession::CONTEXT_DRAFT . '.category_options', $this->lookupService->getInvoicePaymentCategoryOptions($userId)),
                $this->cardContext($session)
            ),
            ConversationSession::STATE_CARD_INVOICE_PAYMENT_CONFIRM => $this->replyBuilder->buildConfirmationPrompt(
                $session->context(ConversationSession::CONTEXT_DRAFT, []),
                $this->cardContext($session)
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

    private function cardContext(ConversationSession $session): array
    {
        return [
            'selected_card_id' => (int) $session->context(ConversationSession::CONTEXT_SELECTED_CARD_ID, 0),
            'selected_card_description' => (string) $session->context(ConversationSession::CONTEXT_SELECTED_CARD_DESCRIPTION, 'Cartao'),
            'selected_card_pay_day' => $session->context(ConversationSession::CONTEXT_SELECTED_CARD_PAY_DAY),
            'selected_card_closure_date' => $session->context(ConversationSession::CONTEXT_SELECTED_CARD_CLOSURE_DATE),
            'selected_card_invoice_total' => (float) $session->context(ConversationSession::CONTEXT_SELECTED_CARD_INVOICE_TOTAL, 0),
            'parent_page' => (int) $session->context(ConversationSession::CONTEXT_PARENT_PAGE, 1),
        ];
    }

    private function cardContextFromQueryResult(ConversationSession $session, array $queryResult): array
    {
        return [
            'selected_card_id' => (int) ($queryResult['card_id'] ?? $session->context(ConversationSession::CONTEXT_SELECTED_CARD_ID, 0)),
            'selected_card_description' => (string) ($queryResult['card_description'] ?? $session->context(ConversationSession::CONTEXT_SELECTED_CARD_DESCRIPTION, 'Cartao')),
            'selected_card_pay_day' => $queryResult['pay_day'] ?? $session->context(ConversationSession::CONTEXT_SELECTED_CARD_PAY_DAY),
            'selected_card_closure_date' => $queryResult['closure_date'] ?? $session->context(ConversationSession::CONTEXT_SELECTED_CARD_CLOSURE_DATE),
            'selected_card_invoice_total' => (float) ($queryResult['open_total'] ?? $queryResult['invoice_total'] ?? $session->context(ConversationSession::CONTEXT_SELECTED_CARD_INVOICE_TOTAL, 0)),
            'parent_page' => (int) $session->context(ConversationSession::CONTEXT_PARENT_PAGE, 1),
        ];
    }
}
