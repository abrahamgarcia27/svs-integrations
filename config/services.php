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

    'monday' => [
        'token' => env('MONDAY_API_TOKEN'),
        'leads' => [
            'board_id' => env('MONDAY_LEADS_BOARD_ID'),
            'group_id' => env('MONDAY_LEADS_LIST_ID'),
            'email_column' => env('MONDAY_LEADS_EMAIL_FIELD_ID', 'email'),
            'phone_column' => env('MONDAY_LEADS_PHONE_FIELD_ID', 'phone'),
            'name_column' => env('MONDAY_LEADS_NAME_FIELD_ID', 'name'),
            'source_column' => env('MONDAY_LEADS_SOURCE_FIELD_ID', 'dup__of_source_mkn2km3h'),
            'country_column' => env('MONDAY_LEADS_COUNTRY_FIELD_ID', 'country'),
            'company_column' => env('MONDAY_LEADS_COMPANY_FIELD_ID', 'company'),
            'logging_enabled' => env('MONDAY_LOGGING_ENABLED', false),
        ],
    ],

];
