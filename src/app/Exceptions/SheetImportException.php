<?php

namespace App\Exceptions;

use RuntimeException;

class SheetImportException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $sourceRow = null)
    {
        parent::__construct($message);
    }
}
