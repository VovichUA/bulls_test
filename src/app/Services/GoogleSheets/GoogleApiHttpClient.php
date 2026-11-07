<?php

namespace App\Services\GoogleSheets;

use App\Exceptions\GoogleSheetsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class GoogleApiHttpClient
{
    public function __construct(
        private readonly int $maxAttempts = 4,
        private readonly int $initialDelayMs = 1000,
        private readonly int $maxDelayMs = 32000,
        private readonly int $jitterMs = 1000,
        private readonly int $timeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 10,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 10 || $initialDelayMs < 0 || $maxDelayMs < $initialDelayMs
            || $maxDelayMs > 60000 || $jitterMs < 0 || $jitterMs > 60000
            || $connectTimeoutSeconds < 1 || $timeoutSeconds < $connectTimeoutSeconds) {
            throw new GoogleSheetsException('Invalid Google Sheets HTTP/retry configuration.');
        }
    }

    /** @param array<string, mixed> $options */
    public function send(string $method, string $url, array $options = []): Response
    {
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $response = null;
            try {
                $response = Http::acceptJson()
                    ->timeout($this->timeoutSeconds)
                    ->connectTimeout($this->connectTimeoutSeconds)
                    ->withoutRedirecting()
                    ->send($method, $url, $options);

                if ($response->successful()) {
                    return $response;
                }
                if (! $this->retryable($response) || $attempt === $this->maxAttempts) {
                    throw $this->failure($response, $attempt);
                }
            } catch (ConnectionException) {
                if ($attempt === $this->maxAttempts) {
                    throw new GoogleSheetsException('Google API connection failed after '.$attempt.' attempts.');
                }
            }
            Sleep::usleep($this->delayMs($attempt, $response) * 1000);
        }
        throw new GoogleSheetsException('Google API request could not be completed.');
    }

    private function retryable(Response $response): bool
    {
        if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
            return true;
        }
        if ($response->status() !== 403) {
            return false;
        }
        $reasons = $this->reasons($response);
        if (array_intersect($reasons, ['SERVICE_DISABLED', 'accessNotConfigured']) !== []) {
            return false;
        }

        return array_intersect($reasons, ['RESOURCE_EXHAUSTED', 'rateLimitExceeded', 'userRateLimitExceeded', 'RATE_LIMIT_EXCEEDED']) !== [];
    }

    /** @return list<string> */
    private function reasons(Response $response): array
    {
        $reasons = [$response->json('error.status')];
        foreach (['error.errors', 'error.details'] as $field) {
            $entries = $response->json($field);
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (is_array($entry)) {
                    $reasons[] = $entry['reason'] ?? null;
                }
            }
        }

        return array_values(array_filter($reasons, 'is_string'));
    }

    private function delayMs(int $attempt, ?Response $response): int
    {
        $delay = $this->initialDelayMs * (2 ** ($attempt - 1)) + random_int(0, $this->jitterMs);
        $retryAfter = trim((string) $response?->header('Retry-After'));
        if ($retryAfter !== '') {
            $seconds = ctype_digit($retryAfter)
                ? (int) $retryAfter
                : max(0, (strtotime($retryAfter) ?: 0) - now()->timestamp);
            $delay = max($delay, $seconds * 1000);
        }

        return (int) min($this->maxDelayMs, $delay);
    }

    private function failure(Response $response, int $attempt): GoogleSheetsException
    {
        $status = $response->status();
        $message = match (true) {
            $status === 429 || ($status === 403 && $this->retryable($response)) => 'Google API rate limit persisted after '.$attempt.' attempts. Retry later.',
            $status === 403 && array_intersect($this->reasons($response), ['SERVICE_DISABLED', 'accessNotConfigured']) !== [] => 'Enable the Google Sheets API in the Service Account project.',
            $status === 400 => 'Google API rejected the request. Check the range and document type; if this is an Excel/Office file, convert it to Google Sheets.',
            $status === 403 => 'Google API access denied. Check that Sheets API is enabled and the file is shared with the Service Account.',
            $status === 404 => 'Spreadsheet or requested resource was not found or is not visible to the Service Account.',
            $status === 401 => 'Google API authentication failed. Check the Service Account credentials.',
            $status >= 500 => 'Google API is unavailable after '.$attempt.' attempts.',
            default => 'Google API rejected the request (HTTP '.$status.').',
        };

        return new GoogleSheetsException($message, $status);
    }
}
