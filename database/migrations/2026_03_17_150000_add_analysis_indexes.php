<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'date'], 'transactions_user_date_idx');
            $table->index(['user_id', 'card_id', 'date'], 'transactions_user_card_date_idx');
            $table->index(['user_id', 'category_id', 'date'], 'transactions_user_category_date_idx');
            $table->index(['user_id', 'type_id', 'payment_method_id', 'date'], 'transactions_user_type_payment_date_idx');
        });

        Schema::table('installments', function (Blueprint $table) {
            $table->index(['card_id', 'pay_day'], 'installments_card_pay_day_idx');
            $table->index(['card_id', 'paid_at', 'pay_day'], 'installments_card_paid_pay_day_idx');
            $table->index('payment_transaction_id', 'installments_payment_transaction_idx');
        });

        Schema::table('spendings', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'spendings_user_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_user_date_idx');
            $table->dropIndex('transactions_user_card_date_idx');
            $table->dropIndex('transactions_user_category_date_idx');
            $table->dropIndex('transactions_user_type_payment_date_idx');
        });

        Schema::table('installments', function (Blueprint $table) {
            $table->dropIndex('installments_card_pay_day_idx');
            $table->dropIndex('installments_card_paid_pay_day_idx');
            $table->dropIndex('installments_payment_transaction_idx');
        });

        Schema::table('spendings', function (Blueprint $table) {
            $table->dropIndex('spendings_user_created_at_idx');
        });
    }
};
