<?php

return [
    'credentials_path' => env('GOOGLE_SHEETS_CREDENTIALS', storage_path('app/private/google-service-account.json')),
    'timeout_seconds' => (int) env('GOOGLE_SHEETS_TIMEOUT', 30),
    'connect_timeout_seconds' => (int) env('GOOGLE_SHEETS_CONNECT_TIMEOUT', 10),
    'retry' => [
        'max_attempts' => (int) env('GOOGLE_SHEETS_MAX_ATTEMPTS', 4),
        'initial_delay_ms' => 1000,
        'max_delay_ms' => 32000,
        'jitter_ms' => 1000,
    ],
];
