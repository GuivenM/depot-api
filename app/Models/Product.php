<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name', 'brand', 'format_cl', 'bottles_per_crate', 'price_crate',
        'price_bottle', 'cost_crate', 'alert_threshold', 'active',
    ];

    // stock_bottles n'est volontairement PAS fillable : seul StockService le modifie.
    protected $casts = ['active' => 'boolean'];

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function getStockCratesAttribute(): int
    {
        return intdiv($this->stock_bottles, max(1, $this->bottles_per_crate));
    }

    public function getStockLooseBottlesAttribute(): int
    {
        return $this->stock_bottles % max(1, $this->bottles_per_crate);
    }
}
