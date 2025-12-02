<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentEvent extends Model
{
    protected $fillable = ['idempotency_key','external_payment_id','order_id','payload','processed_at','result'];
    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
