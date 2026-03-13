<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramWebhookEvent extends Model
{
    use HasFactory;

    public const STATUS_RECEIVED = 'received';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'update_id',
        'telegram_user_id',
        'telegram_chat_id',
        'event_type',
        'payload_json',
        'normalized_payload_json',
        'processing_status',
        'failure_reason',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'normalized_payload_json' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function markAsQueued(): void
    {
        $this->update([
            'processing_status' => self::STATUS_QUEUED,
        ]);
    }

    public function markAsProcessed(array $normalizedPayload = []): void
    {
        $data = [
            'processing_status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
        ];

        if ($normalizedPayload !== []) {
            $data['normalized_payload_json'] = $normalizedPayload;
        }

        $this->update($data);
    }

    public function markAsIgnored(?string $reason = null, array $normalizedPayload = []): void
    {
        $data = [
            'processing_status' => self::STATUS_IGNORED,
            'processed_at' => now(),
            'failure_reason' => $reason,
        ];

        if ($normalizedPayload !== []) {
            $data['normalized_payload_json'] = $normalizedPayload;
        }

        $this->update($data);
    }

    public function markAsFailed(string $reason): void
    {
        $this->update([
            'processing_status' => self::STATUS_FAILED,
            'processed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
