<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $guarded = [];

    protected $casts = ['started_at' => 'datetime', 'validated_at' => 'datetime'];

    public function lines()
    {
        return $this->hasMany(InventoryLine::class);
    }
}
