<?php

namespace App\Exceptions;

use RuntimeException;

class SchemaManagerException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
