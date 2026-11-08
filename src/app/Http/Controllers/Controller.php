<?php

namespace App\Http\Controllers;

use App\Exceptions\GoogleSheetsException;

abstract class Controller
{
    protected function googleError(\Throwable $exception): string
    {
        return $exception instanceof GoogleSheetsException
            ? $exception->getMessage()
            : 'Не вдалося виконати запит до Google. Перевірте налаштування та повторіть спробу.';
    }
}
