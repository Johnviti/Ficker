<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessageJob;
use App\Models\Level;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkCode;
use App\Models\TelegramWebhookEvent;
use App\Services\Telegram\TelegramMessageNormalizer;
use Carbon\Carbon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TelegramProcessTelegramMessageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-21 12:00:00', 'America/Sao_Paulo'));

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
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

    public function test_process_job_marks_event_as_ignored_when_payload_is_out_of_scope(): void
    {
        $event = TelegramWebhookEvent::create([
            'update_id' => 465635102,
            'telegram_user_id' => 7377735021,
            'telegram_chat_id' => 7377735021,
            'event_type' => 'unknown',
            'payload_json' => [
                'update_id' => 465635102,
                'callback_query' => [
                    'id' => 'abc123',
                ],
            ],
            'processing_status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        app()->call([new ProcessTelegramMessageJob($event->id), 'handle']);

        $event->refresh();

        $this->assertSame(TelegramWebhookEvent::STATUS_IGNORED, $event->processing_status);
        $this->assertSame('Evento fora do escopo do MVP.', $event->failure_reason);
        $this->assertSame(false, data_get($event->normalized_payload_json, 'is_supported'));
    }

    public function test_process_job_marks_event_as_processed_when_session_is_expired(): void
    {
        $user = User::factory()->create(['level_id' => 1]);

        TelegramAccount::create([
            'user_id' => $user->id,
            'telegram_user_id' => 7377735022,
            'telegram_chat_id' => 7377735022,
            'telegram_username' => 'expired_user',
            'status' => TelegramAccount::STATUS_VERIFIED,
            'verified_at' => now()->subDay(),
            'last_interaction_at' => now()->subDays(4),
            'session_expires_at' => now()->subMinute(),
        ]);

        $event = TelegramWebhookEvent::create([
            'update_id' => 465635103,
            'telegram_user_id' => 7377735022,
            'telegram_chat_id' => 7377735022,
            'event_type' => 'message_received',
            'payload_json' => [
                'update_id' => 465635103,
                'message' => [
                    'text' => 'oi',
                    'from' => [
                        'id' => 7377735022,
                        'username' => 'expired_user',
                    ],
                    'chat' => [
                        'id' => 7377735022,
                    ],
                ],
            ],
            'processing_status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        app()->call([new ProcessTelegramMessageJob($event->id), 'handle']);

        $event->refresh();

        $this->assertSame(TelegramWebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame('session_expired', data_get($event->normalized_payload_json, 'session.status'));
        $this->assertSame(false, data_get($event->normalized_payload_json, 'reply.success'));
    }

    public function test_process_job_marks_event_as_processed_when_account_is_revoked(): void
    {
        $user = User::factory()->create(['level_id' => 1]);

        TelegramAccount::create([
            'user_id' => $user->id,
            'telegram_user_id' => 7377735023,
            'telegram_chat_id' => 7377735023,
            'telegram_username' => 'revoked_user',
            'status' => TelegramAccount::STATUS_REVOKED,
            'verified_at' => now()->subDay(),
            'last_interaction_at' => null,
            'session_expires_at' => null,
            'revoked_at' => now()->subHour(),
        ]);

        $event = TelegramWebhookEvent::create([
            'update_id' => 465635104,
            'telegram_user_id' => 7377735023,
            'telegram_chat_id' => 7377735023,
            'event_type' => 'message_received',
            'payload_json' => [
                'update_id' => 465635104,
                'message' => [
                    'text' => 'oi',
                    'from' => [
                        'id' => 7377735023,
                        'username' => 'revoked_user',
                    ],
                    'chat' => [
                        'id' => 7377735023,
                    ],
                ],
            ],
            'processing_status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        app()->call([new ProcessTelegramMessageJob($event->id), 'handle']);

        $event->refresh();

        $this->assertSame(TelegramWebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame('revoked', data_get($event->normalized_payload_json, 'session.status'));
        $this->assertSame(false, data_get($event->normalized_payload_json, 'reply.success'));
    }

    public function test_process_job_marks_event_as_failed_when_internal_exception_occurs(): void
    {
        $event = TelegramWebhookEvent::create([
            'update_id' => 465635105,
            'telegram_user_id' => 7377735024,
            'telegram_chat_id' => 7377735024,
            'event_type' => 'message_received',
            'payload_json' => [
                'update_id' => 465635105,
                'message' => [
                    'text' => 'oi',
                    'from' => [
                        'id' => 7377735024,
                        'username' => 'broken_user',
                    ],
                    'chat' => [
                        'id' => 7377735024,
                    ],
                ],
            ],
            'processing_status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        $normalizer = Mockery::mock(TelegramMessageNormalizer::class);
        $normalizer->shouldReceive('normalize')
            ->once()
            ->andThrow(new \RuntimeException('Normalizer exploded'));

        $this->app->instance(TelegramMessageNormalizer::class, $normalizer);

        app()->call([new ProcessTelegramMessageJob($event->id), 'handle']);

        $event->refresh();

        $this->assertSame(TelegramWebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame('Normalizer exploded', $event->failure_reason);
    }
}
