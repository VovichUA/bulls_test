<?php

namespace App\Services\GoogleSheets;

use App\Exceptions\GoogleSheetsException;

class GoogleSheetsClient
{
    private const API_URL = 'https://sheets.googleapis.com/v4/spreadsheets/';

    public function __construct(
        private readonly GoogleApiHttpClient $http,
        private readonly GoogleServiceAccountTokenProvider $tokens,
        private readonly SheetRowMapper $mapper,
    ) {}

    /** @return list<string> */
    public function getSheetTitles(string $spreadsheetId): array
    {
        $this->validate($spreadsheetId);
        $data = $this->request(rawurlencode($spreadsheetId), ['fields' => 'sheets(properties(title))']);
        if (! is_array($data['sheets'] ?? null) || ! array_is_list($data['sheets'])) {
            throw new GoogleSheetsException('Google Sheets returned an invalid sheet list.');
        }
        $titles = [];
        foreach ($data['sheets'] as $sheet) {
            $title = is_array($sheet) ? ($sheet['properties']['title'] ?? null) : null;
            if (! is_string($title) || $title === '') {
                throw new GoogleSheetsException('Google Sheets returned an invalid sheet title.');
            }
            $titles[] = $title;
        }

        return $titles;
    }

    /** @return list<string|null> */
    public function getHeaders(string $spreadsheetId, string $tab, int $headerRow = 1): array
    {
        $this->validate($spreadsheetId, $tab, $headerRow);
        $values = $this->values($spreadsheetId, $this->quotedTab($tab).'!'.$headerRow.':'.$headerRow);

        return $this->mapper->headers($values[0] ?? []);
    }

    /**
     * Convenience projection for display/exploration, not for importers: ambiguity metadata is omitted.
     * Importers must use getData() and validate selected mappings before consuming rows.
     *
     * @return array<int, array<array-key, string|int|float|bool|null>>
     */
    public function getRows(string $spreadsheetId, string $tab, int $headerRow = 1): array
    {
        return $this->getData($spreadsheetId, $tab, $headerRow)['rows'];
    }

    /**
     * Importers must validate their normalized source mappings against headers and ambiguous
     * before consuming rows. Duplicate and unnamed columns are excluded from row projection.
     *
     * @return array{headers: list<string|null>, ambiguous: list<string>, rows: array<int, array<array-key, string|int|float|bool|null>>}
     */
    public function getData(string $spreadsheetId, string $tab, int $headerRow = 1, ?callable $heartbeat = null): array
    {
        $this->validate($spreadsheetId, $tab, $headerRow);
        $values = $this->values($spreadsheetId, $this->quotedTab($tab), $heartbeat);
        $rows = $this->mapper->rows($values, $headerRow);
        $headers = $this->mapper->headers($values[$headerRow - 1]);

        return ['headers' => $headers, 'ambiguous' => $this->mapper->ambiguous($headers), 'rows' => $rows];
    }

    /** @return list<list<mixed>> */
    private function values(string $spreadsheetId, string $range, ?callable $heartbeat = null): array
    {
        $data = $this->request(rawurlencode($spreadsheetId).'/values/'.rawurlencode($range), [
            'majorDimension' => 'ROWS', 'valueRenderOption' => 'UNFORMATTED_VALUE', 'dateTimeRenderOption' => 'FORMATTED_STRING',
        ], $heartbeat);
        if (! is_string($data['range'] ?? null) || ! is_array($data['values'] ?? []) || ! array_is_list($data['values'] ?? [])) {
            throw new GoogleSheetsException('Google Sheets returned an invalid values response.');
        }
        $values = $data['values'] ?? [];
        foreach ($values as $row) {
            if (! is_array($row) || ! array_is_list($row)) {
                throw new GoogleSheetsException('Google Sheets rows must be lists of cells.');
            }
        }

        return $values;
    }

    /** @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function request(string $path, array $query, ?callable $heartbeat = null): array
    {
        $data = $this->http->send('GET', self::API_URL.$path, [
            'headers' => ['Authorization' => 'Bearer '.$this->tokens->getAccessToken($heartbeat)],
            'query' => $query,
        ], $heartbeat)->json();
        if (! is_array($data) || array_is_list($data)) {
            throw new GoogleSheetsException('Google Sheets returned an invalid JSON response.');
        }

        return $data;
    }

    private function quotedTab(string $tab): string
    {
        return "'".str_replace("'", "''", $tab)."'";
    }

    private function validate(string $spreadsheetId, ?string $tab = null, int $headerRow = 1): void
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $spreadsheetId)) {
            throw new GoogleSheetsException('Provide a Google spreadsheet ID, not a URL.');
        }
        if ($tab === '' || $headerRow < 1) {
            throw new GoogleSheetsException('A tab name and header row greater than zero are required.');
        }
    }
}
