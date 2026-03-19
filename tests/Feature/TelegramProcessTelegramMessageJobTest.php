<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessageJob;
use App\Models\Level;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkCode;
use App\Models\TelegramWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramProcessTelegramMessageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Level::factory()->create(['id' => 1]);

        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => '',
            'services.telegram.webhook_secret' => 'secret-123',
            'services.telegram.rate_limit_max_hits' => 15,
            'services.telegram.rate_limit_window_seconds' => 60,
            'services.telegram.session_ttl_hours' => 72,
        ]);
    }

    public function test_process_job_links_account_when_message_contains_valid_link_code(): void
    {
        $user = User::factory()->create(['level_id' => 1]);
        $linkCode = TelegramLinkCode::create([
            'user_id' => $user->id,
            'code' => 'FICKER-123456',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $event = TelegramWebhookEvent::create([
            'update_id' => 465635100,
            'telegram_user_id' => 7377735019,
            'telegram_chat_id' => 7377735019,
            'event_type' => 'message_received',
            'payload_json' => [
                'update_id' => 465635100,
                'message' => [
                    'text' => 'FICKER-123456',
                    'from' => [
                        'id' => 7377735019,
                        'username' => 'airton',
                    ],
                    'chat' => [
                        'id' => 7377735019,
                    ],
                ],
            ],
            'processing_status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        app()->call([new ProcessTelegramMessageJob($event->id), 'handle']);

        $event->refresh();
        $linkCode->refresh();

        $this->assertSame(TelegramWebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame('linked', data_get($event->normalized_payload_json, 'link_result.status'));
        $this->assertNotNull($linkCode->used_at);

        $this->assertDatabaseHas('telegram_accounts', [
            'user_id' => $user->id,
            'telegram_user_id' => 7377735019,
            'telegram_chat_id' => 7377735019,
            'status' => TelegramAccount::STATUS_VERIFIED,
        ]);
    }

    public function test_process_job_marks_event_as_processed_and_replies_when_session_is_not_linked(): void
    {
        $event = TelegramWebhookEvent::create([
            'update_id' => 465635101,
            'telegram_user_id' => 7377735020,
            'telegram_chat_id' => 7377735020,
            'event_type' => 'message_received',
            'payload_json' => [
                'update_id' => 465635101,
                'message' => [
                    'text' => 'oi',
                    'from' => [
                        'id' => 7377735020,
                        'username' => 'guest_user',
                    ],
                    'chat' => [
                        'id' => 7377735020,
                    ],
                ],
            ],
            'processing_status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        app()->call([new ProcessTelegramMessageJob($event->id), 'handle']);

        $event->refresh();

        $this->assertSame(TelegramWebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame('not_linked', data_get($event->normalized_payload_json, 'session.status'));
        $this->assertSame('not_a_code', data_get($event->normalized_payload_json, 'link_code_resolution.status'));
        $this->assertSame(false, data_get($event->normalized_payload_json, 'reply.attempted'));
        $this->assertSame(false, data_get($event->normalized_payload_json, 'reply.success'));
    }
}
