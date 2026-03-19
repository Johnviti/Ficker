<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessageJob;
use App\Models\TelegramWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_returns_service_unavailable_when_telegram_channel_is_disabled(): void
    {
        config([
            'services.telegram.enabled' => false,
            'services.telegram.webhook_secret' => 'secret-123',
        ]);

        $this->postJson('/api/telegram/webhook/secret-123', [
            'update_id' => 1,
        ])->assertStatus(503)
            ->assertJsonPath('message', 'Canal Telegram desabilitado.');
    }

    public function test_webhook_returns_forbidden_when_secret_is_invalid(): void
    {
        config([
            'services.telegram.enabled' => true,
            'services.telegram.webhook_secret' => 'secret-123',
        ]);

        $this->postJson('/api/telegram/webhook/wrong-secret', [
            'update_id' => 1,
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Segredo do webhook invalido.');
    }

    public function test_webhook_persists_event_marks_it_as_queued_and_dispatches_processing_job(): void
    {
        config([
            'services.telegram.enabled' => true,
            'services.telegram.webhook_secret' => 'secret-123',
        ]);

        Queue::fake();

        $payload = [
            'update_id' => 465634999,
            'message' => [
                'message_id' => 999,
                'text' => '0',
                'from' => [
                    'id' => 7377735019,
                    'username' => 'airton',
                ],
                'chat' => [
                    'id' => 7377735019,
                ],
            ],
        ];

        $this->postJson('/api/telegram/webhook/secret-123', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Webhook recebido com sucesso.');

        $event = TelegramWebhookEvent::query()->first();

        $this->assertNotNull($event);
        $this->assertSame(465634999, $event->update_id);
        $this->assertSame('message_received', $event->event_type);
        $this->assertSame(TelegramWebhookEvent::STATUS_QUEUED, $event->processing_status);

        Queue::assertPushed(ProcessTelegramMessageJob::class, function (ProcessTelegramMessageJob $job) use ($event) {
            return $job->eventId === $event->id;
        });
    }
}
