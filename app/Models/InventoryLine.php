<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryLine extends Model
{
    protected $guarded = [];

    protected $casts = ['counted_at' => 'datetime'];

    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function containerType()
    {
        return $this->belongsTo(ContainerType::class);
    }
}
