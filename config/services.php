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

];
