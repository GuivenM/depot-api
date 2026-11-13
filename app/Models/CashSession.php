<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CashSession extends Model
{
    protected $guarded = [];

    protected $casts = ['opened_at' => 'datetime', 'closed_at' => 'datetime'];

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('closed_at');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    /** Seul le cash physique compte dans la caisse théorique ; MoMo est suivi à part. */
    public function summary(): array
    {
        $completed = $this->sales()->where('status', 'completed');

        $cash = (int) (clone $completed)->where('payment_method', 'cash')->sum('total');
        $momo = (int) (clone $completed)->where('payment_method', 'momo')->sum('total');
        $expenses = (int) $this->expenses()->sum('amount');

        return [
            'opening_amount' => (int) $this->opening_amount,
            'cash_sales'     => $cash,
            'momo_sales'     => $momo,
            'expenses'       => $expenses,
            'expected_cash'  => (int) $this->opening_amount + $cash - $expenses,
        ];
    }
}
