<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAccount extends Model
{
    use HasFactory;

    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'user_id',
        'telegram_user_id',
        'telegram_chat_id',
        'telegram_username',
        'status',
        'verified_at',
        'revoked_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_VERIFIED)
            ->whereNull('revoked_at');
    }

    public function scopeByChatId(Builder $query, int|string $chatId): Builder
    {
        return $query->where('telegram_chat_id', $chatId);
    }

    public function scopeByTelegramUserId(Builder $query, int|string $telegramUserId): Builder
    {
        return $query->where('telegram_user_id', $telegramUserId);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_VERIFIED && is_null($this->revoked_at);
    }

    public function revoke(): void
    {
        $this->update([
            'status' => self::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }
}
