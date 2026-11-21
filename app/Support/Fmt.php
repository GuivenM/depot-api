<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/** Mise en forme des documents : montants en FCFA, dates à l'heure du dépôt. */
class Fmt
{
    public static function timezone(): string
    {
        return config('depot.timezone', 'Africa/Porto-Novo');
    }

    /** 14000 -> « 14 000 FCFA » (espaces insécables pour ne pas casser un montant sur deux lignes). */
    public static function money(int|float|null $amount, bool $unit = true): string
    {
        $n = number_format((int) round((float) $amount), 0, ',', "\u{00A0}");

        return $unit ? $n."\u{00A0}FCFA" : $n;
    }

    /** Montant signé : « +3 000 » / « −3 000 ». */
    public static function signed(int $amount): string
    {
        return ($amount > 0 ? '+' : ($amount < 0 ? "\u{2212}" : '')).self::money(abs($amount), false);
    }

    public static function date(CarbonInterface|string|null $value, string $format = 'd/m/Y H:i'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $date = $value instanceof CarbonInterface ? Carbon::instance($value) : Carbon::parse($value, config('app.timezone'));

        return $date->setTimezone(self::timezone())->format($format);
    }

    public static function day(string $ymd): string
    {
        return Carbon::createFromFormat('Y-m-d', $ymd)->format('d/m/Y');
    }
}
