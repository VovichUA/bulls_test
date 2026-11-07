<?php

namespace App\Exceptions;

class RowValidationException extends SheetImportException
{
    /** @param list<array{row: int, column: string, reason: string}> $errors */
    public function __construct(public readonly int $failedRows, array $errors, int $totalErrors)
    {
        parent::__construct(json_encode([
            'message' => 'Invalid rows; the entire target snapshot was rejected.',
            'failed_rows' => $failedRows,
            'total_errors' => $totalErrors,
            'errors' => $errors,
            'truncated' => $totalErrors > count($errors),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $errors[0]['row'] ?? null);
    }
}
