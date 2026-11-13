<?php

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

class InsufficientStock extends RuntimeException
{
    public function __construct(public readonly Product $product)
    {
        parent::__construct("Stock insuffisant pour « {$product->name} ».");
    }

    public function render()
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
