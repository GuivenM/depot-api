<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Entrée du journal d'audit : écrite une fois, jamais modifiée ni supprimée. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal d\'audit est immuable.'));
        static::deleting(fn () => throw new LogicException('Le journal d\'audit est immuable.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
