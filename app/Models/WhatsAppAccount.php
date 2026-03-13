<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class WhatsAppAccount extends Model
{
    use HasFactory;

    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'user_id',
        'phone_e164',
        'provider',
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

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VERIFIED);
    }

    public function scopeByPhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone_e164', $phone);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_VERIFIED)
            ->whereNull('revoked_at');
    }

    public function revoke(): void
    {
        $this->update([
            'status' => self::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }
}
