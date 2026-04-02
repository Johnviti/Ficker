<?php

use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\BalanceController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\InstallmentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SpendingController;
use App\Http\Controllers\TelegramLinkController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('telegram')->group(function () {
    Route::post('/webhook/{secret}', [TelegramWebhookController::class, 'receive']);
});

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::prefix('transaction')->group(function () {
        Route::get('/income', [TransactionController::class, 'incomes']);
        Route::get('/all', [TransactionController::class, 'showTransactions']);
        Route::post('/store', [TransactionController::class, 'store']);
        Route::get('/type/{id}', [TransactionController::class, 'showTransactionsByType']);
        Route::get('/card/{id}', [TransactionController::class, 'showTransactionsByCard']);
        Route::get('/{id}/installments', [InstallmentController::class, 'showInstallments']);
        Route::get('/{id}', [TransactionController::class, 'showTransaction']);
        Route::put('/{id}', [TransactionController::class, 'update']);
        Route::delete('/{id}', [TransactionController::class, 'destroy']);
    });

    Route::post('/category/store', [CategoryController::class, 'store']);
    Route::get('/categories', [CategoryController::class, 'showCategories']);
    Route::get('/categories/{id}', [CategoryController::class, 'showCategory']);
    Route::get('/categories/type/{id}', [CategoryController::class, 'showCategoriesByType']);
    Route::put('/categories/{id}/limit', [CategoryController::class, 'updateLimit']);

    Route::post('/card', [CardController::class, 'store']);
    Route::get('/cards', [CardController::class, 'showCards']);
    Route::get('/cards/{id}/invoice', [CardController::class, 'showCardInvoice']);
    Route::get('/cards/{id}/installments', [CardController::class, 'showInvoiceInstallments']);
    Route::get('/cards/{id}/invoices', [CardController::class, 'showInvoices']);
    Route::post('/cards/{id}/invoices/{pay_day}/pay', [CardController::class, 'payInvoiceByPayDay']);
    Route::post('/cards/{id}/pay-invoice', [CardController::class, 'payNextInvoice']);
    Route::get('/flags', [CardController::class, 'showFlags']);

    Route::get('/spendings', [SpendingController::class, 'spendings']);
    Route::get('/spending', [SpendingController::class, 'showSpending']);
    Route::post('/spending/store', [SpendingController::class, 'store']);
    Route::put('/spending/update/{id}', [SpendingController::class, 'update']);

    Route::get('/balance', [BalanceController::class, 'balance']);

    Route::prefix('analysis')->group(function () {
        Route::get('/summary', [AnalysisController::class, 'summary']);
        Route::get('/timeline', [AnalysisController::class, 'timeline']);
        Route::get('/cards', [AnalysisController::class, 'cards']);
        Route::get('/invoices', [AnalysisController::class, 'invoices']);
        Route::get('/categories', [AnalysisController::class, 'categories']);
        Route::get('/composition', [AnalysisController::class, 'composition']);
        Route::get('/top-expenses', [AnalysisController::class, 'topExpenses']);
    });

    Route::get('/payment/methods', [PaymentController::class, 'showPaymentMethods']);
    Route::get('/telegram/link-status', [TelegramLinkController::class, 'linkStatus']);
    Route::post('/telegram/link-code', [TelegramLinkController::class, 'generateLinkCode']);
    Route::delete('/telegram/link', [TelegramLinkController::class, 'revokeLink']);
});

require __DIR__ . '/auth.php';
