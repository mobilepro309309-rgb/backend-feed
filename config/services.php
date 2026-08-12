<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'supabase' => [
        'url' => env('SUPABASE_URL'),
        'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
    ],

    'storage' => [
        'driver' => env('FILESYSTEM_DRIVER', 'r2'),
        'public_domain' => env('STORAGE_PUBLIC_DOMAIN', ''),
    ],

    'cloudflare_r2' => [
        'account_id' => env('CLOUDFLARE_R2_ACCOUNT_ID'),
        'access_key_id' => env('CLOUDFLARE_R2_ACCESS_KEY_ID'),
        'secret_access_key' => env('CLOUDFLARE_R2_SECRET_ACCESS_KEY'),
        'bucket' => env('CLOUDFLARE_R2_BUCKET', 'app-storage'),
        'public_url' => env('STORAGE_PUBLIC_DOMAIN') ?: env('CLOUDFLARE_R2_PUBLIC_URL', ''),
        'endpoint' => env('CLOUDFLARE_R2_ENDPOINT'),
    ],

    'paymob' => [
        'api_key' => env('PAYMOB_API_KEY'),
        'public_key' => env('PAYMOB_PUBLIC_KEY'),
        'secret_key' => env('PAYMOB_SECRET_KEY'),
        'hmac' => env('PAYMOB_HMAC'),
        'integration_id' => env('PAYMOB_INTEGRATION_ID'),
        'cash_integration_id' => env('PAYMOB_CASH_INTEGRATION_ID'),
        'iframe_id' => env('PAYMOB_IFRAME_ID'),
    ],

];