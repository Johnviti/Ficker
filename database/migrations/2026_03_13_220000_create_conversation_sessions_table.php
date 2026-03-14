<?php

use App\Models\User;
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
        Schema::create('conversation_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 30);
            $table->string('external_chat_id', 100);
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('state', 60);
            $table->json('context_json')->nullable();
            $table->dateTime('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_chat_id']);
            $table->index('state');
            $table->index('last_message_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_sessions');
    }
};
