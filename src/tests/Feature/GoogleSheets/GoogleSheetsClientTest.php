<?php

namespace Tests\Feature\GoogleSheets;

use App\Exceptions\GoogleSheetsApiException;
use App\Exceptions\GoogleSheetsException;
use App\Services\GoogleSheets\GoogleSheetsClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleSheetsClientTest extends TestCase
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_URL = 'https://sheets.googleapis.com/v4/spreadsheets/';

    private static ?string $privateKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        Storage::fake('local');
        if (self::$privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, self::$privateKey);
        }
        Storage::disk('local')->put('service-account.json', json_encode([
            'type' => 'service_account', 'client_email' => 'test@example.iam.gserviceaccount.com',
            'private_key' => self::$privateKey, 'token_uri' => self::TOKEN_URL,
        ], JSON_THROW_ON_ERROR));
        config([
            'google-sheets.credentials_path' => Storage::disk('local')->path('service-account.json'),
            'google-sheets.retry.max_attempts' => 3,
            'google-sheets.retry.initial_delay_ms' => 1000,
            'google-sheets.retry.max_delay_ms' => 32000,
            'google-sheets.retry.jitter_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    public function test_service_account_exchange_uses_readonly_scope_and_lists_titles(): void
    {
        $this->fakeGoogle(['sheets' => [['properties' => ['title' => 'Sales']], ['properties' => ['title' => 'Products']]]]);
        $this->assertSame(['Sales', 'Products'], $this->client()->getSheetTitles('spreadsheet-id'));
        $oauth = Http::recorded(fn (Request $request) => $request->url() === self::TOKEN_URL)->sole()[0];
        parse_str($oauth->body(), $form);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        $payload = json_decode(base64_decode(strtr(explode('.', $form['assertion'])[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://www.googleapis.com/auth/spreadsheets.readonly', $payload['scope']);
        $this->assertSame(self::TOKEN_URL, $payload['aud']);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::API_URL)
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['fields'] === 'sheets(properties(title))');
    }

    public function test_headers_escape_tab_title_and_select_the_configured_row(): void
    {
        $this->fakeGoogle(['range' => "'O''Brien ! Q1'!3:3", 'values' => [["\u{00A0} Price ", 'PRODUCT  NAME']]]);
        $this->assertSame(['price', 'product name'], $this->client()->getHeaders('spreadsheet-id', "O'Brien ! Q1", 3));
        Http::assertSent(fn (Request $request) => rawurldecode((string) parse_url($request->url(), PHP_URL_PATH))
            === "/v4/spreadsheets/spreadsheet-id/values/'O''Brien ! Q1'!3:3");
    }

    public function test_rows_use_one_values_request_and_keep_source_row_numbers(): void
    {
        $this->fakeGoogle(['range' => "'Sales'!A1:C8", 'values' => [
            ['Title'], [], [' Name ', 'PRICE', 'Active'], ['Tea', 0, false], [], ['Coffee', 2.5],
        ]]);
        $this->assertSame([
            4 => ['name' => 'Tea', 'price' => 0, 'active' => false],
            6 => ['name' => 'Coffee', 'price' => 2.5, 'active' => null],
        ], $this->client()->getRows('spreadsheet-id', 'Sales', 3));
        $this->assertSame(1, $this->apiRequestCount());
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::API_URL)
            && $request['majorDimension'] === 'ROWS'
            && $request['valueRenderOption'] === 'UNFORMATTED_VALUE'
            && $request['dateTimeRenderOption'] === 'FORMATTED_STRING');
    }

    public function test_tokens_are_reused_and_refreshed_before_expiry(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::sequence()
                ->push(['access_token' => 'first-token', 'expires_in' => 3600, 'token_type' => 'Bearer'])
                ->push(['access_token' => 'second-token', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            self::API_URL.'*' => Http::response(['sheets' => []]),
        ]);
        $client = $this->client();
        $client->getSheetTitles('id');
        $client->getSheetTitles('id');
        $this->travel(3541)->seconds();
        $client->getSheetTitles('id');
        $this->assertSame(2, Http::recorded(fn (Request $request) => $request->url() === self::TOKEN_URL)->count());
        $api = Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::API_URL))->values();
        $this->assertTrue($api[1][0]->hasHeader('Authorization', 'Bearer first-token'));
        $this->assertTrue($api[2][0]->hasHeader('Authorization', 'Bearer second-token'));
    }

    #[DataProvider('permanentErrors')]
    public function test_permanent_errors_are_not_retried_or_exposed(int $status): void
    {
        $this->fakeGoogle(['error' => ['message' => 'SENSITIVE RESPONSE', 'errors' => [['reason' => 'forbidden']]]], $status);
        try {
            $this->client()->getSheetTitles('id');
            $this->fail('Expected a permanent Google API error.');
        } catch (GoogleSheetsException $exception) {
            $this->assertSame($status, $exception->getCode());
            $this->assertInstanceOf(GoogleSheetsApiException::class, $exception);
            $this->assertFalse($exception->retryable);
            $this->assertStringNotContainsString('SENSITIVE', $exception->getMessage());
        }
        $this->assertSame(1, $this->apiRequestCount());
        Sleep::assertNeverSlept();
    }

    /** @return array<string, array{int}> */
    public static function permanentErrors(): array
    {
        return ['invalid range' => [400], 'unauthorized' => [401], 'no access' => [403], 'not found' => [404]];
    }

    public function test_quota_and_server_errors_retry_with_exponential_backoff(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::API_URL.'*' => Http::sequence()->push([], 429)->push([], 503)->push(['sheets' => []]),
        ]);
        $this->assertSame([], $this->client()->getSheetTitles('id'));
        $this->assertSame(3, $this->apiRequestCount());
        Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(2)->seconds()]);
    }

    public function test_rate_limit_403_is_retried(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::API_URL.'*' => Http::sequence()
                ->push(['error' => ['errors' => [['reason' => 'userRateLimitExceeded']]]], 403)
                ->push(['sheets' => []]),
        ]);
        $this->assertSame([], $this->client()->getSheetTitles('id'));
        $this->assertSame(2, $this->apiRequestCount());
        Sleep::assertSleptTimes(1);
    }

    public function test_retries_stop_at_configured_attempt_limit(): void
    {
        $this->fakeGoogle([], 429);
        try {
            $this->client()->getSheetTitles('id');
            $this->fail('Expected quota exhaustion.');
        } catch (GoogleSheetsException $exception) {
            $this->assertSame(429, $exception->getCode());
        }
        $this->assertSame(3, $this->apiRequestCount());
        Sleep::assertSleptTimes(2);
    }

    public function test_retry_after_seconds_is_respected_within_cap(): void
    {
        config(['google-sheets.retry.max_delay_ms' => 2000]);
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::API_URL.'*' => Http::sequence()->push([], 429, ['Retry-After' => '120'])->push(['sheets' => []]),
        ]);
        $this->assertSame([], $this->client()->getSheetTitles('id'));
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    }

    public function test_retry_after_http_date_is_supported(): void
    {
        $this->travelTo(now()->startOfSecond());
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::API_URL.'*' => Http::sequence()
                ->push([], 503, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', now()->addSeconds(4)->timestamp)])
                ->push(['sheets' => []]),
        ]);
        $this->assertSame([], $this->client()->getSheetTitles('id'));
        Sleep::assertSequence([Sleep::for(4)->seconds()]);
    }

    public function test_connection_failures_are_bounded_and_sanitized(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::API_URL.'*' => Http::failedConnection('SENSITIVE NETWORK DETAIL'),
        ]);
        try {
            $this->client()->getSheetTitles('id');
            $this->fail('Expected a connection failure.');
        } catch (GoogleSheetsException $exception) {
            $this->assertSame(0, $exception->getCode());
            $this->assertStringNotContainsString('SENSITIVE', $exception->getMessage());
        }
        Sleep::assertSleptTimes(2);
    }

    public function test_oauth_requests_use_the_same_retry_transport(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::sequence()->push([], 503)->push($this->token()),
            self::API_URL.'*' => Http::response(['sheets' => []]),
        ]);
        $this->assertSame([], $this->client()->getSheetTitles('id'));
        $this->assertSame(2, Http::recorded(fn (Request $request) => $request->url() === self::TOKEN_URL)->count());
        Sleep::assertSleptTimes(1);
    }

    public function test_missing_credentials_fail_lazily_without_network_calls(): void
    {
        config(['google-sheets.credentials_path' => null]);
        $client = $this->client();
        $this->get('/')->assertOk();
        try {
            $client->getSheetTitles('id');
            $this->fail('Expected a credentials configuration error.');
        } catch (GoogleSheetsException $exception) {
            $this->assertStringContainsString('credentials', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_invalid_credentials_are_not_exposed(): void
    {
        Storage::disk('local')->put('service-account.json', '{SENSITIVE PRIVATE KEY');
        try {
            $this->client()->getSheetTitles('id');
            $this->fail('Expected an invalid credentials error.');
        } catch (GoogleSheetsException $exception) {
            $this->assertStringNotContainsString('SENSITIVE', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_malformed_token_response_is_rejected(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['expires_in' => 3600])]);
        $this->expectException(GoogleSheetsException::class);
        $this->client()->getSheetTitles('id');
    }

    public function test_invalid_values_response_is_rejected(): void
    {
        $this->fakeGoogle(['range' => 'Sales', 'values' => 'invalid']);
        $this->expectException(GoogleSheetsException::class);
        $this->client()->getRows('id', 'Sales');
    }

    public function test_invalid_header_row_is_rejected_before_authentication(): void
    {
        $this->expectException(GoogleSheetsException::class);
        try {
            $this->client()->getHeaders('id', 'Sales', 0);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_data_exposes_ambiguity_without_overwriting_duplicate_values(): void
    {
        $this->fakeGoogle(['range' => "'Sales'!A1:F10", 'values' => [
            [], [], ['Name', 'Total', '', ' TOTAL '], ['Tea', 10, 'Notes', 20, false],
        ]]);
        $this->assertSame([
            'headers' => ['name', 'total', null, 'total'],
            'ambiguous' => ['total'],
            'rows' => [4 => ['name' => 'Tea']],
        ], $this->client()->getData('id', 'Sales', 3));
        $this->assertSame(1, $this->apiRequestCount());
    }

    #[DataProvider('machineErrors')]
    public function test_machine_error_fields_control_retry_and_safe_messages(array $error, bool $retry, string $message): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::API_URL.'*' => Http::sequence()->push(['error' => $error], 403)->push(['sheets' => []]),
        ]);
        if ($retry) {
            $this->assertSame([], $this->client()->getSheetTitles('id'));
        } else {
            try {
                $this->client()->getSheetTitles('id');
                $this->fail('Expected a permanent error.');
            } catch (GoogleSheetsException $exception) {
                $this->assertSame(403, $exception->getCode());
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
        $this->assertSame($retry ? 2 : 1, $this->apiRequestCount());
    }

    public static function machineErrors(): array
    {
        return [
            'rate limit' => [['errors' => [['reason' => 'rateLimitExceeded']]], true, ''],
            'user rate limit' => [['errors' => [['reason' => 'userRateLimitExceeded']]], true, ''],
            'quota details' => [['details' => [['reason' => 'RATE_LIMIT_EXCEEDED']]], true, ''],
            'permission' => [['status' => 'PERMISSION_DENIED', 'message' => 'rateLimitExceeded'], false, 'access denied'],
            'legacy disabled' => [['errors' => [['reason' => 'accessNotConfigured']]], false, 'Enable the Google Sheets API'],
            'disabled details' => [['status' => 'PERMISSION_DENIED', 'details' => [['reason' => 'SERVICE_DISABLED']]], false, 'Enable the Google Sheets API'],
            'disabled status' => [['status' => 'SERVICE_DISABLED'], false, 'Enable the Google Sheets API'],
        ];
    }

    public function test_400_suggests_office_conversion_without_echoing_server_message(): void
    {
        $this->fakeGoogle(['error' => ['message' => 'SENSITIVE DOCUMENT']], 400);
        try {
            $this->client()->getRows('id', 'Sales');
            $this->fail('Expected unsupported document or invalid request error.');
        } catch (GoogleSheetsException $exception) {
            $this->assertStringContainsString('convert it to Google Sheets', $exception->getMessage());
            $this->assertStringNotContainsString('SENSITIVE', $exception->getMessage());
        }
        $this->assertSame(1, $this->apiRequestCount());
    }

    public function test_url_is_rejected_before_any_network_call(): void
    {
        $this->expectException(GoogleSheetsException::class);
        try {
            $this->client()->getSheetTitles('https://docs.google.com/spreadsheets/d/id/edit');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_heartbeat_runs_on_each_oauth_and_sheets_attempt_without_leaking_to_next_call(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::sequence()->push([], 503)->push($this->token()),
            self::API_URL.'*' => Http::sequence()->push([], 429)
                ->push(['range' => 'Sales!A1:A2', 'values' => [['Name'], ['Tea']]])
                ->push(['range' => 'Sales!A1:A2', 'values' => [['Name'], ['Tea']]]),
        ]);
        $beats = 0;
        $client = $this->client();
        $data = $client->getData('id', 'Sales', 1, function () use (&$beats): void {
            $beats++;
        });
        $this->assertSame([2 => ['name' => 'Tea']], $data['rows']);
        $this->assertSame(6, $beats);
        $client->getData('id', 'Sales');
        $this->assertSame(6, $beats);
    }

    public function test_heartbeat_failure_during_oauth_is_not_a_cacheable_http_error(): void
    {
        Http::fake();
        try {
            $this->client()->getData('id', 'Sales', 1, function (): never {
                throw new \RuntimeException('PRIVATE HEARTBEAT FAILURE');
            });
            $this->fail('Heartbeat failure should abort authentication.');
        } catch (GoogleSheetsException $exception) {
            $this->assertNotInstanceOf(GoogleSheetsApiException::class, $exception);
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    private function client(): GoogleSheetsClient
    {
        return app(GoogleSheetsClient::class);
    }

    /** @return array{access_token: string, expires_in: int, token_type: string} */
    private function token(): array
    {
        return ['access_token' => 'test-token', 'expires_in' => 3600, 'token_type' => 'Bearer'];
    }

    /** @param array<string, mixed> $payload */
    private function fakeGoogle(array $payload, int $status = 200): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::API_URL.'*' => Http::response($payload, $status),
        ]);
    }

    private function apiRequestCount(): int
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::API_URL))->count();
    }
}
