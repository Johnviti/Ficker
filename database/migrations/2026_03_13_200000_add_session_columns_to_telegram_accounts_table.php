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
        Schema::table('telegram_accounts', function (Blueprint $table) {
            $table->dateTime('last_interaction_at')->nullable()->after('verified_at');
            $table->dateTime('session_expires_at')->nullable()->after('last_interaction_at');

            $table->index('session_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_accounts', function (Blueprint $table) {
            $table->dropIndex(['session_expires_at']);
            $table->dropColumn(['last_interaction_at', 'session_expires_at']);
        });
    }
};
