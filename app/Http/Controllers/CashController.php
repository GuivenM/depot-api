<?php

namespace App\Http\Controllers;

use App\Exceptions\DepotException;
use App\Models\CashSession;
use App\Services\CashService;
use Illuminate\Http\Request;

class CashController extends Controller
{
    public function __construct(private CashService $cash)
    {
    }

    public function current(Request $request)
    {
        $session = $this->cash->current($request->user());

        return ['session' => $session, 'summary' => $session?->summary()];
    }

    public function open(Request $request)
    {
        $data = $request->validate(['opening_amount' => 'required|integer|min:0']);

        $session = $this->cash->open($request->user(), $data['opening_amount']);

        return response()->json(['session' => $session, 'summary' => $session->summary()], 201);
    }

    /** Clôture de sa propre caisse ; le gérant peut clôturer celle d'un autre (session_id). */
    public function close(Request $request)
    {
        $data = $request->validate([
            'counted_cash' => 'required|integer|min:0',
            'note'         => 'nullable|string|max:255',
            'session_id'   => 'nullable|integer|exists:cash_sessions,id',
        ]);

        $user = $request->user();

        if (isset($data['session_id']) && $user->role === 'gerant') {
            $session = CashSession::findOrFail($data['session_id']);
        } else {
            $session = $this->cash->current($user) ?? throw DepotException::noOpenCashSession();
        }

        $closed = $this->cash->close($session, $user, $data['counted_cash'], $data['note'] ?? null);

        return ['session' => $closed, 'summary' => $closed->summary()];
    }

    public function expense(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|string|max:60',
            'amount'   => 'required|integer|min:1',
            'note'     => 'nullable|string|max:255',
        ]);

        $expense = $this->cash->addExpense($request->user(), $data['category'], $data['amount'], $data['note'] ?? null);

        return response()->json($expense, 201);
    }
}
