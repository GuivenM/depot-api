<?php

namespace App\Http\Controllers;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\DocumentService;
use App\Services\PdfRenderer;
use App\Services\ReportService;
use App\Support\Fmt;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Documents imprimables : PDF (reçus, relevés, rapports) et export CSV des ventes. */
class DocumentController extends Controller
{
    public function __construct(
        private DocumentService $docs,
        private PdfRenderer $pdf,
        private ReportService $reports,
    ) {
    }

    /** Reçu d'une vente : le gérant voit tout, les autres seulement leurs ventes. */
    public function receipt(Request $request, Sale $sale)
    {
        $this->ownOrManager($request, (int) $sale->user_id, 'Cette vente n\'est pas la vôtre.');

        $data = $this->docs->receipt($sale);

        return $this->pdfResponse('pdf.receipt', $data, 'recu-'.$data['number'].'.pdf', 'a5');
    }

    /** Relevé de compte d'un client (par défaut : les 90 derniers jours). */
    public function statement(Request $request, Customer $customer)
    {
        $input = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);

        $from = $input['from'] ?? Carbon::parse($input['to'] ?? 'now', Fmt::timezone())->subDays(89)->toDateString();
        $p = $this->reports->period($from, $input['to'] ?? null);

        return $this->pdfResponse('pdf.statement', $this->docs->statement($customer, $p), 'releve-client-'.$customer->id.'.pdf');
    }

    /** Clôture (ou état en cours) d'une caisse : le gérant voit toutes les caisses, les autres la leur. */
    public function cashClosing(Request $request, CashSession $session)
    {
        $this->ownOrManager($request, (int) $session->user_id, 'Cette caisse n\'est pas la vôtre.');

        $data = $this->docs->cashClosing($session);

        return $this->pdfResponse('pdf.cash-closing', $data, 'caisse-'.$data['number'].'.pdf');
    }

    public function activity(Request $request)
    {
        $p = $this->period($request);

        return $this->pdfResponse('pdf.activity', $this->docs->activity($p), 'rapport-activite-'.$p['from'].'_'.$p['to'].'.pdf');
    }

    public function receivables()
    {
        return $this->pdfResponse('pdf.receivables', $this->docs->receivables(), 'creances-clients.pdf');
    }

    public function stock()
    {
        return $this->pdfResponse('pdf.stock', $this->docs->stockValuation(), 'etat-du-stock.pdf');
    }

    /** Export des ventes de la période, une ligne par article (ouvrable dans Excel : UTF-8 avec BOM, séparateur « ; »). */
    public function salesCsv(Request $request)
    {
        $p = $this->period($request);

        $headers = ['Vente', 'Date', 'Vendeur', 'Client', 'Paiement', 'Statut', 'Produit', 'Unité', 'Quantité', 'Prix unitaire', 'Total ligne'];

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers, ';', '"', '');
        foreach ($this->docs->salesRows($p) as $row) {
            fputcsv($out, array_map([$this, 'csvCell'], $row), ';', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ventes_'.$p['from'].'_'.$p['to'].'.csv"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    private function pdfResponse(string $view, array $data, string $filename, string $paper = 'a4')
    {
        return response($this->pdf->render($view, $data, $paper), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    private function ownOrManager(Request $request, int $ownerId, string $message): void
    {
        $user = $request->user();

        if ($user->role !== 'gerant' && (int) $user->id !== $ownerId) {
            abort(403, $message);
        }
    }

    /** Dates en YYYY-MM-DD (heure du dépôt) ; par défaut : aujourd'hui. */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);

        return $this->reports->period($data['from'] ?? null, $data['to'] ?? null);
    }

    /** Neutralise l'injection de formules (=, +, -, @) dans les cellules texte ouvertes sous Excel. */
    private function csvCell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
