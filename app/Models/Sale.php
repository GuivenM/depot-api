<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $guarded = [];

    protected $casts = ['sold_at' => 'datetime'];

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function session()
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
