<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Section 5: block overselling. Negative stock has no batch to draw cost from
 * and breaks FIFO permanently.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $available,
        public readonly int $requested,
        ?string $message = null,
    ) {
        parent::__construct($message ?? __('Not enough stock: :count available.', ['count' => $available]));
    }
}
