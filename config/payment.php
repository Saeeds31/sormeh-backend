<?php


return [
    'default' => env('PAYMENT_DEFAULT_GATEWAY', 'zarinpal'),

    'zarinpal' => [
        'active' => env('ZARINPAL_ACTIVE', true),
        'merchant_id' => env('ZARINPAL_MERCHANT_ID', ''),
        'sandbox' => env('ZARINPAL_SANDBOX', true),
        'callback_url' => env('ZARINPAL_CALLBACK_URL', '/api/v1/gateway/callback'),
    ],

    'payir' => [
        'active' => env('PAYIR_ACTIVE', true),
        'api_key' => env('PAYIR_API_KEY', ''),
        'sandbox' => env('PAYIR_SANDBOX', true),
        'callback_url' => env('PAYIR_CALLBACK_URL', '/api/v1/gateway/callback'),
    ],

    'saman' => [
        'active' => env('SAMAN_ACTIVE', true),
        'merchant_id' => env('SAMAN_MERCHANT_ID', ''),
        'terminal_id' => env('SAMAN_TERMINAL_ID', ''),
        'callback_url' => env('SAMAN_CALLBACK_URL', '/api/v1/gateway/callback'),
    ],
    'snapppay' => [
        'base_url' => env('SNAPPPAY_BASE_URL', 'https://api.snapppay.ir'),
        'client_id' => env('SNAPPPAY_CLIENT_ID'),
        'client_secret' => env('SNAPPPAY_CLIENT_SECRET'),
        'username' => env('SNAPPPAY_USERNAME'),
        'password' => env('SNAPPPAY_PASSWORD'),
    ],
    'fake' => [
        'active' => env('FAKE_GATEWAY_ACTIVE', true),
        'auto_approve' => env('FAKE_GATEWAY_AUTO_APPROVE', true),
    ],
];
