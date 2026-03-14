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
    public const STATE_INVOICES_MENU = 'invoices_menu';
    public const STATE_TRANSACTIONS_PAGE = 'transactions_page';
    public const CONTEXT_PREVIOUS_STATE = 'previous_state';
    public const CONTEXT_PAGE = 'page';
    public const CONTEXT_PER_PAGE = 'per_page';

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
