<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use DateTimeInterface;

class Installment extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'installment_description',
        'installment_value',
        'pay_day',
        'card_id',
        'paid_at',
        'payment_transaction_id'
    ];

    protected $casts = [
        'pay_day' => 'date:Y-m-d',    //só dia
        'paid_at' => 'datetime',      //data + hora
    ];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return Carbon::instance($date)
            ->timezone('America/Sao_Paulo')
            ->format('Y-m-d H:i:s');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'payment_transaction_id');
    }
}
