<?php

namespace App\Services\GoogleSheets;

use App\Exceptions\GoogleSheetsException;

class HeaderNormalizer
{
    public function normalize(string $header): string
    {
        $trimmed = preg_replace('/^[\s\x{200B}\x{FEFF}]+|[\s\x{200B}\x{FEFF}]+$/u', '', $header);
        $collapsed = $trimmed === null ? null : preg_replace('/\s+/u', ' ', $trimmed);

        if ($collapsed === null) {
            throw new GoogleSheetsException('Column headers must be valid UTF-8 text.');
        }

        return mb_strtolower($collapsed, 'UTF-8');
    }
}
