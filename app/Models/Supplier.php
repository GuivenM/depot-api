<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    // `balance` n'est volontairement PAS fillable : seul SupplierService le modifie.
    protected $fillable = ['name', 'phone', 'note', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function ledger()
    {
        return $this->hasMany(SupplierLedgerEntry::class);
    }
}
