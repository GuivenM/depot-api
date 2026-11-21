<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/** Seul endroit qui connaît la bibliothèque PDF (barryvdh/laravel-dompdf). */
class PdfRenderer
{
    public function render(string $view, array $data, string $paper = 'a4', string $orientation = 'portrait'): string
    {
        return Pdf::loadView($view, $data)->setPaper($paper, $orientation)->output();
    }
}
