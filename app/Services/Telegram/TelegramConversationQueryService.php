<?php

namespace App\Services\Telegram;

class TelegramConversationQueryService
{
    public function __construct(
        private readonly TelegramCardsQueryService $cardsQueryService,
        private readonly TelegramTransactionsPaginationService $transactionsPaginationService,
        private readonly TelegramFinancialQueryService $financialQueryService
    ) {
    }

    public function getCardsSummary(int $userId, int $page = 1, int $perPage = 4): array
    {
        return $this->cardsQueryService->getCardsSummary($userId, $page, $perPage);
    }

    public function getInvoicesSummary(int $userId): array
    {
        return $this->cardsQueryService->getInvoicesSummary($userId);
    }

    public function getCardInvoiceItems(int $userId, int $cardId, int $page = 1, int $perPage = 5): array
    {
        return $this->cardsQueryService->getCardInvoiceItems($userId, $cardId, $page, $perPage);
    }

    public function getTransactionsPage(int $userId, int $page = 1, int $perPage = 5): array
    {
        return $this->transactionsPaginationService->getPage($userId, $page, $perPage);
    }

    public function getBalance(int $userId): array
    {
        return $this->financialQueryService->getBalance($userId);
    }
}
