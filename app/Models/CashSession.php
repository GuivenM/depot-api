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

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function supplierPayments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** Caisse théorique = fond + ventes cash + remboursements de dette en cash - dépenses - règlements fournisseurs en cash. MoMo et crédit sont suivis à part. */
    public function summary(): array
    {
        $completed = $this->sales()->where('status', 'completed');

        $cash = (int) (clone $completed)->where('payment_method', 'cash')->sum('total');
        $momo = (int) (clone $completed)->where('payment_method', 'momo')->sum('total');
        $credit = (int) (clone $completed)->where('payment_method', 'credit')->sum('total');
        $payCash = (int) $this->payments()->whereNull('voided_at')->where('method', 'cash')->sum('amount');
        $payMomo = (int) $this->payments()->whereNull('voided_at')->where('method', 'momo')->sum('amount');
        $expenses = (int) $this->expenses()->sum('amount');
        $supplierCash = (int) $this->supplierPayments()->whereNull('voided_at')->where('method', 'cash')->sum('amount');

        return [
            'opening_amount' => (int) $this->opening_amount,
            'cash_sales'     => $cash,
            'momo_sales'     => $momo,
            'credit_sales'   => $credit,
            'customer_payments_cash' => $payCash,
            'customer_payments_momo' => $payMomo,
            'expenses'       => $expenses,
            'supplier_payments_cash' => $supplierCash,
            'expected_cash'  => (int) $this->opening_amount + $cash + $payCash - $expenses - $supplierCash,
        ];
    }
}
