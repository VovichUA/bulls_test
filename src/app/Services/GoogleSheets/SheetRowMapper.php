<?php

namespace App\Services\GoogleSheets;

use App\Exceptions\GoogleSheetsException;

class SheetRowMapper
{
    public function __construct(private readonly HeaderNormalizer $normalizer) {}

    /** @param list<mixed> $row
     * @return list<string|null>
     */
    public function headers(array $row): array
    {
        if ($row === [] || ! array_is_list($row)) {
            throw new GoogleSheetsException('A non-empty header row is required.');
        }

        $headers = [];
        foreach ($row as $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new GoogleSheetsException('Column headers must be scalar labels or null.');
            }
            $header = $this->normalizer->normalize((string) $value);
            $headers[] = $header === '' ? null : $header;
        }

        return $headers;
    }

    /** @param list<string|null> $headers
     * @return list<string>
     */
    public function ambiguous(array $headers): array
    {
        $counts = array_count_values(array_filter($headers, fn (?string $header): bool => $header !== null));

        return array_map('strval', array_keys(array_filter($counts, fn (int $count): bool => $count > 1)));
    }

    /** @param list<list<mixed>> $values
     * @return array<int, array<array-key, string|int|float|bool|null>>
     */
    public function rows(array $values, int $headerRow = 1): array
    {
        if ($headerRow < 1 || ! array_is_list($values) || ! isset($values[$headerRow - 1]) || ! is_array($values[$headerRow - 1])) {
            throw new GoogleSheetsException('The configured header row is missing or invalid.');
        }

        $headers = $this->headers($values[$headerRow - 1]);
        $ambiguous = $this->ambiguous($headers);
        $columns = array_filter($headers, fn (?string $header): bool => $header !== null && ! in_array($header, $ambiguous, true));
        $result = [];
        for ($index = $headerRow; $index < count($values); $index++) {
            $row = $values[$index];
            if (! is_array($row) || ! array_is_list($row)) {
                throw new GoogleSheetsException('Google Sheets rows must be lists of cells.');
            }
            foreach ($row as $value) {
                if ($value !== null && ! is_scalar($value)) {
                    throw new GoogleSheetsException('Google Sheets cells must contain scalar values.');
                }
            }
            if (array_filter($row, fn (mixed $value): bool => ! $this->isEmpty($value)) === []) {
                continue;
            }
            $mapped = [];
            foreach ($columns as $column => $header) {
                $mapped[$header] = $row[$column] ?? null;
            }
            $result[$index + 1] = $mapped;
        }

        return $result;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
