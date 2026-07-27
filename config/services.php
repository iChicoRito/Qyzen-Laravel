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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/oauth/google/callback'),
    ],

    // Task 28: Groq-hosted educator assistant. The key is server-side only — it is read by
    // App\Services\Ai\GroqClient and nowhere else, and never reaches a Blade view or a log line.
    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),

        // Defaults are the free ("on_demand") tier figures for openai/gpt-oss-120b. These are
        // Groq's limits, not ours — raising them here does NOT buy more capacity, it only moves
        // the rejection from our budget check to Groq's 429. Change them when the account tier or
        // the model actually changes (openai/gpt-oss-120b, for one, is 6000 TPM not 8000).
        'limits' => [
            'requests_per_minute' => (int) env('GROQ_RPM', 30),
            'requests_per_day' => (int) env('GROQ_RPD', 1000),
            'tokens_per_minute' => (int) env('GROQ_TPM', 8000),
            'tokens_per_day' => (int) env('GROQ_TPD', 200000),
        ],
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

];
