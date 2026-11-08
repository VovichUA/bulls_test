<?php

namespace App\Services\Importing;

use App\Exceptions\GoogleSheetsApiException;
use App\Exceptions\GoogleSheetsException;
use App\Exceptions\ImportConfigurationException;
use App\Exceptions\RowValidationException;
use App\Exceptions\SchemaLockTimeoutException;
use App\Exceptions\SchemaManagerException;
use Throwable;

class FailureClassifier
{
    public function classify(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof SchemaLockTimeoutException => 'schema_lock_timeout',
            $exception instanceof RowValidationException => 'row_validation',
            $exception instanceof ImportConfigurationException => 'configuration',
            $exception instanceof GoogleSheetsApiException && ! $exception->retryable && in_array($exception->getCode(), [400, 401, 403, 404], true) => 'api_permanent',
            $exception instanceof GoogleSheetsException => 'transient',
            $exception instanceof SchemaManagerException => $exception->retryable ? 'transient' : 'configuration',
            default => 'internal',
        };
    }
}
