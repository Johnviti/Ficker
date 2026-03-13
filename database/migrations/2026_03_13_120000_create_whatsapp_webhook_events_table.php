<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_type', 50);
            $table->string('phone_e164', 20)->nullable();
            $table->string('provider_message_id', 120)->nullable();
            $table->json('payload_json');
            $table->json('headers_json')->nullable();
            $table->json('normalized_payload_json')->nullable();
            $table->string('processing_status', 30)->default('received');
            $table->text('failure_reason')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('provider');
            $table->index('event_type');
            $table->index('phone_e164');
            $table->index('provider_message_id');
            $table->index('processing_status');
            $table->index(['provider', 'provider_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
    }
};
