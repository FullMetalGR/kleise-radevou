<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The billing provider
    |--------------------------------------------------------------------------
    |
    | The platform's own billing provider, the one that charges tenants for
    | this product. Nothing reads these at boot: a
    | missing key is refused at the first question asked of the provider, so
    | an application without billing keys still serves every tenant.
    |
    | The class is the port's binding. Left unset, it is the Stripe adapter,
    | which refuses its first question with a sentence naming whichever key
    | is missing: the secret key for the API, the webhook signing secret for
    | a notification. The test environment names the fake here, as it names
    | the array mail transport, so every process a test run starts meets the
    | fake and none charges anybody.
    |
    | Automatic tax is a switch, off by default: the checkout asks the
    | provider to work out the tax only where it is true, which waits on a
    | head office address and a registration in the provider's tax settings
    |. The tax id is collected either way.
    |
    */

    'provider' => [
        'class' => env('BILLING_PROVIDER'),
        'secret_key' => env('BILLING_SECRET_KEY'),
        'webhook_secret' => env('BILLING_WEBHOOK_SECRET'),
        'automatic_tax' => env('BILLING_AUTOMATIC_TAX', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Grace
    |--------------------------------------------------------------------------
    |
    | How many days a failed charge's grace window runs before the tenant is
    | lapsed. A second failure inside the window never
    | extends it. Seven is a placeholder the owner sets; a value that is not a
    | whole number of days above nought is refused where a failed charge asks.
    |
    */

    'grace_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | Every plan the platform sells: the name it is shown by, the provider
    | price it is sold under, read from the environment, the feature slugs it
    | grants, and the monthly price the Billing screen and the site's pricing
    | page display, in minor units beside its currency. A price no plan names
    | buys the floor and nothing more.
    |
    | The names are the plans' one home: Solo for the floor
    | alone and Team for the floor plus staff. The Billing screen, the
    | console's plan column and the pricing page read them from here, and
    | nothing derives a plan's name from its features.
    |
    | The display amounts are the prices, EUR 14.95 and
    | EUR 24.95 a month ex VAT, and must agree with the provider prices the
    | environment names, which nothing here can check. A plan whose price the
    | environment leaves unset or blank makes the catalogue unreadable: every
    | subscription sync changes nothing and logs the plan and the key until it
    | is set, rather than resolving that plan's subscribers to the floor in
    | silence, and the site's pricing page shows its cards with no amount.
    |
    */

    'plans' => [
        'floor' => [
            'name' => 'Solo',
            'price' => env('BILLING_FLOOR_PRICE'),
            'features' => [],
            'display' => ['amount' => 1495, 'currency' => 'EUR'],
        ],
        'staff' => [
            'name' => 'Team',
            'price' => env('BILLING_STAFF_PRICE'),
            'features' => ['staff'],
            'display' => ['amount' => 2495, 'currency' => 'EUR'],
        ],
    ],

];
