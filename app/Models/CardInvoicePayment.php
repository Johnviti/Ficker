<?php

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardInvoicePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'card_id',
        'pay_day',
        'payment_transaction_id',
        'amount_paid',
        'payment_method_id',
        'category_id',
        'paid_at',
    ];

    protected $casts = [
        'pay_day' => 'date:Y-m-d',
        'paid_at' => 'datetime',
        'amount_paid' => 'float',
    ];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return Carbon::instance($date)
            ->timezone('America/Sao_Paulo')
            ->format('Y-m-d H:i:s');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'payment_transaction_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
