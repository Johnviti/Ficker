<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationSession extends Model
{
    use HasFactory;

    public const STATE_MAIN_MENU = 'main_menu';
    public const STATE_CARDS_SUMMARY = 'cards_summary';
    public const STATE_CARD_INVOICE_ITEMS = 'card_invoice_items';
    public const STATE_CARD_INVOICE_PAYMENT_METHOD = 'card_invoice_payment_method';
    public const STATE_CARD_INVOICE_PAYMENT_CATEGORY = 'card_invoice_payment_category';
    public const STATE_CARD_INVOICE_PAYMENT_CONFIRM = 'card_invoice_payment_confirm';
    public const STATE_INVOICES_MENU = 'invoices_menu';
    public const STATE_TRANSACTIONS_PAGE = 'transactions_page';
    public const STATE_TRANSACTION_INCOME_VALUE = 'transaction_income_value';
    public const STATE_TRANSACTION_INCOME_DESCRIPTION = 'transaction_income_description';
    public const STATE_TRANSACTION_INCOME_CATEGORY = 'transaction_income_category';
    public const STATE_TRANSACTION_INCOME_DATE = 'transaction_income_date';
    public const STATE_TRANSACTION_INCOME_CONFIRM = 'transaction_income_confirm';
    public const STATE_TRANSACTION_EXPENSE_VALUE = 'transaction_expense_value';
    public const STATE_TRANSACTION_EXPENSE_DESCRIPTION = 'transaction_expense_description';
    public const STATE_TRANSACTION_EXPENSE_CATEGORY = 'transaction_expense_category';
    public const STATE_TRANSACTION_EXPENSE_DATE = 'transaction_expense_date';
    public const STATE_TRANSACTION_EXPENSE_PAYMENT_METHOD = 'transaction_expense_payment_method';
    public const STATE_TRANSACTION_EXPENSE_CARD = 'transaction_expense_card';
    public const STATE_TRANSACTION_EXPENSE_INSTALLMENTS = 'transaction_expense_installments';
    public const STATE_TRANSACTION_EXPENSE_CONFIRM = 'transaction_expense_confirm';
    public const STATE_CATEGORY_CREATE_TYPE = 'category_create_type';
    public const STATE_CATEGORY_CREATE_DESCRIPTION = 'category_create_description';
    public const STATE_CATEGORY_CREATE_CONFIRM = 'category_create_confirm';
    public const CONTEXT_PREVIOUS_STATE = 'previous_state';
    public const CONTEXT_PAGE = 'page';
    public const CONTEXT_PER_PAGE = 'per_page';
    public const CONTEXT_CARD_OPTIONS = 'card_options';
    public const CONTEXT_SELECTED_CARD_ID = 'selected_card_id';
    public const CONTEXT_SELECTED_CARD_DESCRIPTION = 'selected_card_description';
    public const CONTEXT_SELECTED_CARD_PAY_DAY = 'selected_card_pay_day';
    public const CONTEXT_SELECTED_CARD_CLOSURE_DATE = 'selected_card_closure_date';
    public const CONTEXT_SELECTED_CARD_INVOICE_TOTAL = 'selected_card_invoice_total';
    public const CONTEXT_PARENT_PAGE = 'parent_page';
    public const CONTEXT_FLOW = 'flow';
    public const CONTEXT_DRAFT = 'draft';
    public const CONTEXT_STEP_HISTORY = 'step_history';

    protected $fillable = [
        'channel',
        'external_chat_id',
        'user_id',
        'state',
        'context_json',
        'last_message_at',
    ];

    protected $casts = [
        'context_json' => 'array',
        'last_message_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function context(string $key, mixed $default = null): mixed
    {
        return data_get($this->context_json ?? [], $key, $default);
    }

    public function setState(string $state, array $context = []): void
    {
        $this->update([
            'state' => $state,
            'context_json' => $context,
            'last_message_at' => now(),
        ]);
    }

    public function touchMessage(?int $userId = null): void
    {
        $payload = [
            'last_message_at' => now(),
        ];

        if (!is_null($userId)) {
            $payload['user_id'] = $userId;
        }

        $this->update($payload);
    }
}
