<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContainerType extends Model
{
    // `empty_stock` n'est volontairement PAS fillable : seul ContainerService le modifie.
    protected $fillable = ['name', 'deposit_amount', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function movements()
    {
        return $this->hasMany(ContainerMovement::class);
    }
}
