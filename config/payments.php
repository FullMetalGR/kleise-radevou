<?php

use App\Application\Payment\PaymentLog;
use App\Application\Payment\PlatformConfiguration;
use App\Application\Payment\Stripe\StripeAdapter;
use App\Application\Payment\Stripe\StripeTransport;
use App\Infrastructure\Payment\ConfiguredPlatform;
use App\Infrastructure\Payment\LoggedPayments;
use App\Infrastructure\Payment\Stripe\HttpStripeTransport;

return [

    /*
    |--------------------------------------------------------------------------
    | The payment providers
    |--------------------------------------------------------------------------
    |
    | The providers a tenant may connect to take its customers' money:
    | each an adapter class that declares its
    | descriptor and is registered in this order. Adding a provider is a line
    | here and its own directory, and nothing else anywhere.
    |
    | These are the tenants' providers and have nothing to do with the
    | platform's own billing provider of config/billing.php.
    |
    */

    'providers' => [
        StripeAdapter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | A provider registered beside them
    |--------------------------------------------------------------------------
    |
    | One more adapter class, registered after the list above. Unset outside a
    | test run. The test environment names its fake provider here, as it names
    | the array mail transport, so every process a test run starts holds a
    | second provider in the registry and none of them reaches a real one.
    |
    */

    'provider_beside' => env('PAYMENT_PROVIDER_BESIDE'),

    /*
    |--------------------------------------------------------------------------
    | What the adapters are built with
    |--------------------------------------------------------------------------
    |
    | The ports an adapter declares beside itself, each with the class under
    | app/Infrastructure that answers it, bound before the adapters above are
    | built. A test binds its own fake in their place.
    |
    */

    'bindings' => [
        StripeTransport::class => HttpStripeTransport::class,
        PlatformConfiguration::class => ConfiguredPlatform::class,
        PaymentLog::class => LoggedPayments::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | The platform's own partner credentials
    |--------------------------------------------------------------------------
    |
    | Platform mode: a tenant connects through
    | the platform's partner account with the provider, read here by provider
    | slug and field name. A provider whose keys are not all set offers no
    | platform mode and its platform webhook answers 404. These are not the
    | billing provider's keys of config/billing.php, though the vendor may be
    | the same, and nothing here is read by billing.
    |
    */

    'platform' => [
        'stripe' => [
            'secret_key' => env('PAYMENT_PLATFORM_STRIPE_SECRET_KEY'),
            'webhook_secret' => env('PAYMENT_PLATFORM_STRIPE_WEBHOOK_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The platform's fee
    |--------------------------------------------------------------------------
    |
    | What a payment taken through a platform connection pays the platform:
    | a percentage, a fixed amount, or both,
    | coming out of the tenant's side and never added to the booking's total.
    | It has no default. While neither part is set, or either cannot be read,
    | platform mode is offered by no provider; a fee of zero is a fee, and the
    | mode is offered and takes nothing.
    |
    | percent: the percentage of the booking's total, a decimal of at most two
    | places from 0 to 100 ("1.5" is one and a half percent), rounded half up
    | to the total's own minor unit.
    |
    | fixed: an amount per currency in that currency's minor units, as
    | "EUR:25,JPY:30"; a currency not named takes no fixed part.
    |
    | The fee on a payment is the two added, and never more than the total.
    | A payment already opened keeps the fee it was opened with.
    |
    */

    'platform_fee' => [
        'percent' => env('PAYMENT_PLATFORM_FEE_PERCENT'),
        'fixed' => env('PAYMENT_PLATFORM_FEE_FIXED'),
    ],

];
