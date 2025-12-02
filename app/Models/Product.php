<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name','price','stock','reserved_count'];

    public function holds()
    {
        return $this->hasMany(Hold::class);
    }

    public function available(): int
    {
        return $this->stock - $this->reserved_count;
    }

}
