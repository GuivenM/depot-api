<?php

namespace App\Exceptions;

use RuntimeException;

/** Erreur métier renvoyée telle quelle au client (JSON). */
class DepotException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function noOpenCashSession(): self
    {
        return new self('Aucune caisse ouverte : ouvrez votre caisse avant de vendre.', 409);
    }

    public static function cashSessionAlreadyOpen(): self
    {
        return new self('Vous avez déjà une caisse ouverte.', 409);
    }

    public static function cashSessionClosed(): self
    {
        return new self('Cette caisse est déjà clôturée.', 409);
    }

    public function render()
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
