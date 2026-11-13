<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContainerMovement extends Model
{
    protected $guarded = [];

    public function type()
    {
        return $this->belongsTo(ContainerType::class, 'container_type_id');
    }

    public function reference()
    {
        return $this->morphTo();
    }
}
