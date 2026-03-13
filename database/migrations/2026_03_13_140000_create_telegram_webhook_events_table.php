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
        Schema::create('telegram_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->nullable();
            $table->unsignedBigInteger('telegram_user_id')->nullable();
            $table->bigInteger('telegram_chat_id')->nullable();
            $table->string('event_type', 50);
            $table->json('payload_json');
            $table->json('normalized_payload_json')->nullable();
            $table->string('processing_status', 30)->default('received');
            $table->text('failure_reason')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('update_id');
            $table->index('telegram_user_id');
            $table->index('telegram_chat_id');
            $table->index('event_type');
            $table->index('processing_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_webhook_events');
    }
};
