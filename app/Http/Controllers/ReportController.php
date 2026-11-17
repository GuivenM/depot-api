<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports)
    {
    }

    public function overview(Request $request)
    {
        return $this->reports->overview($this->period($request));
    }

    public function daily(Request $request)
    {
        return $this->reports->daily($this->period($request, 92));
    }

    public function topProducts(Request $request)
    {
        $limit = (int) ($request->validate(['limit' => 'nullable|integer|min:1|max:50'])['limit'] ?? 10);

        return $this->reports->topProducts($this->period($request), $limit);
    }

    public function cashSessions(Request $request)
    {
        return $this->reports->cashSessions($this->period($request));
    }

    public function stockAlerts()
    {
        return $this->reports->stockAlerts();
    }

    /** Dates en YYYY-MM-DD (heure du dépôt) ; par défaut : aujourd'hui. */
    private function period(Request $request, int $maxDays = 366): array
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to'   => 'nullable|date_format:Y-m-d',
        ]);

        return $this->reports->period($data['from'] ?? null, $data['to'] ?? null, $maxDays);
    }
}
