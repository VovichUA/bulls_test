<?php

namespace App\Services\GoogleSheets;

use App\Exceptions\GoogleSheetsException;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class GoogleServiceAccountTokenProvider
{
    private ?string $accessToken = null;

    private int $expiresAt = 0;

    public function __construct(
        private readonly GoogleApiHttpClient $http,
        private readonly ?string $credentialsPath,
    ) {}

    public function getAccessToken(?callable $heartbeat = null): string
    {
        if ($this->accessToken !== null && $this->expiresAt > now()->timestamp + 60) {
            return $this->accessToken;
        }

        $credentials = $this->credentials();
        try {
            $account = new ServiceAccountCredentials('https://www.googleapis.com/auth/spreadsheets.readonly', $credentials);
            $token = $account->fetchAuthToken(function (RequestInterface $request) use ($heartbeat): ResponseInterface {
                return $this->http->send($request->getMethod(), (string) $request->getUri(), [
                    'headers' => $request->getHeaders(),
                    'body' => (string) $request->getBody(),
                ], $heartbeat)->toPsrResponse();
            });
        } catch (GoogleSheetsException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GoogleSheetsException('Unable to authenticate the Google Service Account. Check the credentials JSON and private key.');
        }

        $accessToken = $token['access_token'] ?? null;
        $expiresIn = filter_var($token['expires_in'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
        if (! is_string($accessToken) || ! preg_match('/^[\x21-\x7E]+$/D', $accessToken) || $expiresIn === false) {
            throw new GoogleSheetsException('Google returned an invalid access token response.');
        }
        $this->accessToken = $accessToken;
        $this->expiresAt = now()->timestamp + $expiresIn;

        return $this->accessToken;
    }

    /** @return array<string, mixed> */
    private function credentials(): array
    {
        $path = $this->credentialsPath;
        if ($path === null || $path === '') {
            throw new GoogleSheetsException('Google Service Account credentials are not configured. Set GOOGLE_SHEETS_CREDENTIALS.');
        }
        $path = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! is_file($path) || ! is_readable($path)) {
            throw new GoogleSheetsException('Google Service Account credentials file is missing or unreadable. Check GOOGLE_SHEETS_CREDENTIALS.');
        }
        try {
            $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new GoogleSheetsException('Google Service Account credentials must be valid JSON.');
        }
        if (! is_array($data) || ($data['type'] ?? null) !== 'service_account'
            || ! is_string($data['client_email'] ?? null) || ! filter_var($data['client_email'], FILTER_VALIDATE_EMAIL)
            || ! is_string($data['private_key'] ?? null) || $data['private_key'] === ''
            || ($data['universe_domain'] ?? 'googleapis.com') !== 'googleapis.com') {
            throw new GoogleSheetsException('Expected googleapis.com service_account credentials with client_email and private_key.');
        }

        return $data;
    }
}
