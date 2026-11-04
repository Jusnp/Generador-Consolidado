<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'authentik' => [
        'enabled' => filter_var(env('AUTHENTIK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => rtrim((string) env('AUTHENTIK_BASE_URL', ''), '/'),
        'client_id' => env('AUTHENTIK_CLIENT_ID'),
        'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
        'redirect_uri' => env('AUTHENTIK_REDIRECT_URI'),
        'authorize_url' => rtrim((string) env('AUTHENTIK_BASE_URL', ''), '/').'/application/o/authorize/',
        'token_url' => rtrim((string) env('AUTHENTIK_BASE_URL', ''), '/').'/application/o/token/',
        'userinfo_url' => rtrim((string) env('AUTHENTIK_BASE_URL', ''), '/').'/application/o/userinfo/',
        'scopes' => env('AUTHENTIK_SCOPES', 'openid profile email'),
        'api_base_url' => rtrim((string) env('AUTHENTIK_BASE_URL', ''), '/').'/api/v3',
        'api_token' => env('AUTHENTIK_API_TOKEN'),

        // Modo "Subject mode" del proveedor OIDC de SIRUTA en Authentik:
        // hashed_user_id (uid), user_id, user_uuid o user_username.
        'subject_mode' => env('AUTHENTIK_SUBJECT_MODE', 'hashed_user_id'),

        // UUID del grupo exclusivo de SIRUTA al que se agregan las cuentas que SIRUTA crea (opcional).
        'access_group' => env('AUTHENTIK_ACCESS_GROUP'),

        // Ruta organizativa de las cuentas que SIRUTA crea en Authentik.
        'user_path' => env('AUTHENTIK_USER_PATH', 'users/siruta'),
    ],

];
