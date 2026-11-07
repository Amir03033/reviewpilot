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

    /*
    | Google Business Profile connection (OAuth client from Google Cloud Console).
    | The redirect URI must be registered exactly in the OAuth client.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/integrations/google/callback'),
        'scopes' => ['openid', 'email', 'https://www.googleapis.com/auth/business.manage'],

        // Pub/Sub push notifications for new/updated reviews.
        'pubsub_topic' => env('GOOGLE_PUBSUB_TOPIC'), // projects/{project}/topics/{topic}
        'pubsub_audience' => env('GOOGLE_PUBSUB_AUDIENCE'), // audience configured on the push subscription
        'pubsub_service_account' => env('GOOGLE_PUBSUB_SERVICE_ACCOUNT'), // service account email of the push subscription

        'endpoints' => [
            'auth' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'revoke' => 'https://oauth2.googleapis.com/revoke',
            'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'certs' => 'https://www.googleapis.com/oauth2/v1/certs',
            'accounts' => 'https://mybusinessaccountmanagement.googleapis.com/v1',
            'business_information' => 'https://mybusinessbusinessinformation.googleapis.com/v1',
            'reviews' => 'https://mybusiness.googleapis.com/v4',
            'notifications' => 'https://mybusinessnotifications.googleapis.com/v1',
        ],
    ],

    // Claude (Anthropic API) powers review analysis and reply drafting.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL'),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 120),
    ],

];
