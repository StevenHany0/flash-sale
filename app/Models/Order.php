<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    
    protected $fillable = ['hold_id','qty','status','paid_at'];

    public function hold()
    {
        return $this->belongsTo(Hold::class);
    }
}
