<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Social Media Platform Configuration
    |--------------------------------------------------------------------------
    */

    'social' => [
        'auto_post_enabled' => env('SOCIAL_AUTO_POST_ENABLED', false),
    ],

    'instagram' => [
        'client_id'     => env('SOCIAL_INSTAGRAM_CLIENT_ID'),
        'client_secret' => env('SOCIAL_INSTAGRAM_CLIENT_SECRET'),
        'redirect_uri'  => env('SOCIAL_INSTAGRAM_REDIRECT_URI'),
    ],

    'twitter' => [
        'client_id'     => env('SOCIAL_TWITTER_CLIENT_ID'),
        'client_secret' => env('SOCIAL_TWITTER_CLIENT_SECRET'),
        'redirect_uri'  => env('SOCIAL_TWITTER_REDIRECT_URI'),
    ],

    'linkedin' => [
        'client_id'     => env('SOCIAL_LINKEDIN_CLIENT_ID'),
        'client_secret' => env('SOCIAL_LINKEDIN_CLIENT_SECRET'),
        'redirect_uri'  => env('SOCIAL_LINKEDIN_REDIRECT_URI'),
    ],

    'facebook' => [
        'client_id'     => env('SOCIAL_FACEBOOK_CLIENT_ID'),
        'client_secret' => env('SOCIAL_FACEBOOK_CLIENT_SECRET'),
        'redirect_uri'  => env('SOCIAL_FACEBOOK_REDIRECT_URI'),
    ],

    'tiktok' => [
        'client_key'    => env('SOCIAL_TIKTOK_CLIENT_KEY'),
        'client_secret' => env('SOCIAL_TIKTOK_CLIENT_SECRET'),
        'redirect_uri'  => env('SOCIAL_TIKTOK_REDIRECT_URI'),
    ],

    'youtube' => [
        'client_id'     => env('SOCIAL_YOUTUBE_CLIENT_ID'),
        'client_secret' => env('SOCIAL_YOUTUBE_CLIENT_SECRET'),
        'redirect_uri'  => env('SOCIAL_YOUTUBE_REDIRECT_URI'),
    ],

];
