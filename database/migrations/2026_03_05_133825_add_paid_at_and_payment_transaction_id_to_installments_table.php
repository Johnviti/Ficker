<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->timestamp('paid_at')
                ->nullable()
                ->after('pay_day');

            $table->foreignId('payment_transaction_id')
                ->nullable()
                ->after('paid_at')
                ->constrained('transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_transaction_id');
            $table->dropColumn('paid_at');
        });
    }
};
