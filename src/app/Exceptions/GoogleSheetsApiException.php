<?php

namespace App\Exceptions;

class GoogleSheetsApiException extends GoogleSheetsException
{
    public function __construct(string $message, int $status, public readonly bool $retryable)
    {
        parent::__construct($message, $status);
    }
}
