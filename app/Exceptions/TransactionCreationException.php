<?php

namespace App\Exceptions;

use Exception;

class TransactionCreationException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly array $errors = []
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
