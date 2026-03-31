<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->date('pay_day');
            $table->foreignId('payment_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->decimal('amount_paid', 15, 2);
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->index(['card_id', 'pay_day'], 'card_invoice_payments_card_pay_day_idx');
            $table->index(['payment_transaction_id'], 'card_invoice_payments_payment_tx_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_invoice_payments');
    }
};
