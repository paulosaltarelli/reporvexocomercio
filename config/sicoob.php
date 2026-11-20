<?php

return [
    'tenant_id' => env('SICOOB_PIX_TENANT_ID'),
    'client_id' => env('SICOOB_PIX_CLIENT_ID'),
    'key' => env('SICOOB_PIX_KEY'),
    'api_url' => env('SICOOB_PIX_API_URL'),
    'token_url' => env('SICOOB_PIX_TOKEN_URL'),
    'cert' => env('SICOOB_PIX_CERT_PATH'),
    'private_key' => env('SICOOB_PIX_PRIVATE_KEY_PATH'),
    'passphrase' => env('SICOOB_PIX_PRIVATE_KEY_PASSWORD', ''),
];
