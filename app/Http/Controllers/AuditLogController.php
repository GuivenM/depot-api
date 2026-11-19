<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\ReportService;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /** Journal d'audit (lecture seule, gérant). Filtres : action (« user.* » accepté), user_id, subject_type, subject_id, from + to. */
    public function index(Request $request, ReportService $reports)
    {
        $request->validate([
            'action'       => 'nullable|string|max:60',
            'user_id'      => 'nullable|integer',
            'subject_type' => 'nullable|string|max:40',
            'subject_id'   => 'nullable|integer',
            'from'         => 'nullable|date_format:Y-m-d|required_with:to',   // la période se donne en entier : from + to
            'to'           => 'nullable|date_format:Y-m-d|required_with:from',
        ]);

        $query = AuditLog::query()->with('user:id,name')->orderByDesc('id');

        if ($action = $request->query('action')) {
            str_ends_with($action, '*')
                ? $query->where('action', 'like', substr($action, 0, -1).'%')
                : $query->where('action', $action);
        }
        foreach (['user_id', 'subject_type', 'subject_id'] as $filter) {
            if ($request->query($filter) !== null) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->query('from')) {
            $p = $reports->period($request->query('from'), $request->query('to'), 366);   // heure du dépôt
            $query->whereBetween('created_at', [$p['start'], $p['end']]);
        }

        $page = $query->paginate(50);
        $page->setCollection($page->getCollection()->map(fn (AuditLog $l) => [
            'id'           => $l->id,
            'action'       => $l->action,
            'user'         => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name] : null,
            'subject_type' => $l->subject_type,
            'subject_id'   => $l->subject_id,
            'details'      => $l->details,
            'note'         => $l->note,
            'ip'           => $l->ip,
            'created_at'   => $l->created_at,
        ]));

        return $page;
    }
}
