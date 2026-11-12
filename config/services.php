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

    /*
     * Cloudflare Turnstile on the signup form and the site's contact form: on while both keys are
     * set and off while either is unset, when neither form renders a widget and the server checks
     * nothing. The secret key is read by the check at the call and never logged; the site key is
     * public. The widget's two scripts are fixed here beside them and never typed on a page: the
     * signup page's, which waits for its React page to render the widget, and the contact page's,
     * which draws the widget from the page's markup, since that page runs no script of the site's
     * own. Each is read by its form's page alone. Nothing here is shared with payments or billing.
     */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
        'page_script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
    ],

    /*
     * Google Maps for the business page's map: the address geocoded and the map image fetched
     * server-side by a queued job after a settings save that changed the address, the image
     * stored and served from the tenant's own origin. While the key is unset there is no map and
     * nothing asks Google. The key is read at the call and never logged, answered or queued.
     * Nothing here is shared with payments or billing.
     */
    'google' => [
        'maps_key' => env('GOOGLE_MAPS_KEY'),
    ],

    /*
     * Google Analytics: GA4 is the site's one tag, on the central host alone and behind consent,
     * never on a tenant host, the embed, the admin or the console. While the measurement id is
     * unset there is no banner, no loader and no cookie-settings control, and nothing is asked.
     * The script's address is the consent script's to load once a visitor accepts, Google's
     * gtag/js unless the environment names another; the browser suite points it at the central
     * host, so no test reaches Google. The id is public, printed on every central page while it
     * is set.
     */
    'google_analytics' => [
        'measurement_id' => env('GA_MEASUREMENT_ID'),
        'script' => env('GA_SCRIPT', 'https://www.googletagmanager.com/gtag/js'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
