<?php

return [
    'issuance_paused' => env('C6_PLATFORM_PIX_ISSUANCE_PAUSED', false),
    'enabled' => env('C6_PLATFORM_PIX_ENABLED', false),
    'environment' => env('C6_PLATFORM_PIX_ENVIRONMENT'),
    'approval_reference' => env('C6_PLATFORM_PIX_APPROVAL_REFERENCE'),
    'api_url' => env('C6_PLATFORM_PIX_API_URL'),
    'token_url' => env('C6_PLATFORM_PIX_TOKEN_URL'),
    'auth_method' => env('C6_PLATFORM_PIX_AUTH_METHOD'),
    'scope' => env('C6_PLATFORM_PIX_SCOPE'),
    'client_id' => env('C6_PLATFORM_PIX_CLIENT_ID'),
    'client_secret' => env('C6_PLATFORM_PIX_CLIENT_SECRET'),
    'cert' => env('C6_PLATFORM_PIX_CERT_PATH'),
    'private_key' => env('C6_PLATFORM_PIX_PRIVATE_KEY_PATH'),
    'passphrase' => env('C6_PLATFORM_PIX_PRIVATE_KEY_PASSWORD', ''),
    'key' => env('C6_PLATFORM_PIX_KEY'),
    // Independent sandbox credentials for a demonstration alongside an active production account.
    'sandbox' => [
        'enabled' => env('C6_PLATFORM_PIX_SANDBOX_ENABLED', false),
        'environment' => 'sandbox',
        'approval_reference' => env('C6_PLATFORM_PIX_SANDBOX_APPROVAL_REFERENCE'),
        'api_url' => env('C6_PLATFORM_PIX_SANDBOX_API_URL'),
        'token_url' => env('C6_PLATFORM_PIX_SANDBOX_TOKEN_URL'),
        'auth_method' => env('C6_PLATFORM_PIX_SANDBOX_AUTH_METHOD'),
        'scope' => env('C6_PLATFORM_PIX_SANDBOX_SCOPE'),
        'client_id' => env('C6_PLATFORM_PIX_SANDBOX_CLIENT_ID'),
        'client_secret' => env('C6_PLATFORM_PIX_SANDBOX_CLIENT_SECRET'),
        'cert' => env('C6_PLATFORM_PIX_SANDBOX_CERT_PATH'),
        'private_key' => env('C6_PLATFORM_PIX_SANDBOX_PRIVATE_KEY_PATH'),
        'passphrase' => env('C6_PLATFORM_PIX_SANDBOX_PRIVATE_KEY_PASSWORD', ''),
        'key' => env('C6_PLATFORM_PIX_SANDBOX_KEY'),
    ],
];
