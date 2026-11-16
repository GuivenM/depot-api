<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierLedgerEntry extends Model
{
    protected $table = 'supplier_ledger';

    protected $guarded = [];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }
}
