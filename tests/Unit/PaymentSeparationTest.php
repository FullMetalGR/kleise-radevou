<?php

/*
 * Two walls the payment port stands behind, read off the files themselves.
 *
 * Nobody outside an adapter names a provider: no use case, controller, job, message, view or
 * page spells an adapter's namespace or a provider's slug, so adding a provider edits nothing
 * but its own directory and the list in config/payments.php. And payments and the platform's
 * billing share nothing: no payment file names billing and no billing file names the payment
 * port, the two fakes included. And the platform's fee is not an entitlement and gates nothing:
 * no feature check, entitlement or subscription state reads it.
 */

use App\Console\Commands\ExportLangCommand;

/**
 * @param  list<string>  $extensions
 * @return list<string>
 */
function sourceFilesUnder(string $directory, array $extensions): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_dir($path)) {
            $files = [...$files, ...sourceFilesUnder($path, $extensions)];
        } elseif (in_array(pathinfo($entry, PATHINFO_EXTENSION), $extensions, true)) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/**
 * The files under the given directories whose text matches the pattern, relative to the root.
 *
 * @param  list<string>  $directories
 * @param  list<string>  $except  path prefixes left out
 * @return list<string>
 */
function filesNaming(string $pattern, array $directories, array $except = []): array
{
    $root = dirname(__DIR__, 2);
    $found = [];

    foreach ($directories as $directory) {
        foreach (sourceFilesUnder($root.'/'.$directory, ['php', 'ts', 'tsx']) as $path) {
            $relative = substr($path, strlen($root) + 1);
            $excepted = array_filter($except, fn (string $prefix): bool => str_starts_with($relative, $prefix)) !== [];

            if (! $excepted && preg_match($pattern, (string) file_get_contents($path)) === 1) {
                $found[] = $relative;
            }
        }
    }

    return $found;
}

/**
 * Those of the given files, relative to the root, whose text matches the pattern.
 *
 * @param  list<string>  $paths
 * @return list<string>
 */
function pathsNaming(string $pattern, array $paths): array
{
    $root = dirname(__DIR__, 2);

    return array_values(array_filter($paths, fn (string $path): bool => preg_match($pattern, (string) file_get_contents($root.'/'.$path)) === 1));
}

it('names no payment provider outside its own adapter: no class of an adapter directory and no provider slug as a literal anywhere under app, routes or resources', function () {
    $adapterClass = '/Application\\\\Payment\\\\[A-Z][A-Za-z]*\\\\/';
    $slugLiteral = '/[\'"`](stripe|paypal|viva|cardlink|worldline|nexi)[\'"`]/';
    $everywhere = ['app', 'routes', 'resources/js', 'resources/views'];
    $providerNamespace = '/(?<![A-Za-z\\\\])(Stripe|PayPal|Paypal|Viva|Cardlink|Worldline|Nexi)\\\\[A-Z]/';
    $adapters = ['app/Application/Payment/Stripe/', 'app/Infrastructure/Payment/Stripe/'];
    // The admin's generated module is lang/ written out for its tests, never code: it carries
    // payments.php, whose words for a provider's fields sit under its slug. The bundle's module
    // holds no slug and is walked like any other file.
    $generated = [ExportLangCommand::ADMIN_MODULE];

    expect(filesNaming($adapterClass, $everywhere, $adapters))->toBe([])
        ->and(filesNaming($slugLiteral, $everywhere, [...$adapters, ...$generated]))->toBe([])
        ->and(filesNaming($providerNamespace, $everywhere, $adapters))->toBe([]);
});

it('sees an adapter class and a slug literal where a file does name one, so the walk above is not blind', function () {
    $root = dirname(__DIR__, 2);

    expect(filesNaming('/[\'"`](stripe|paypal|viva|cardlink|worldline|nexi)[\'"`]/', ['app/Application/Payment/Stripe']))->toBe(['app/Application/Payment/Stripe/StripeAdapter.php'])
        ->and(filesNaming('/Application\\\\Payment\\\\[A-Z][A-Za-z]*\\\\/', ['config']))->toBe(['config/payments.php'])
        ->and(preg_match('/(?<![A-Za-z\\\\])(Stripe|PayPal|Paypal|Viva|Cardlink|Worldline|Nexi)\\\\[A-Z]/', 'return new Stripe\\StripeAdapter;'))->toBe(1)
        ->and(is_dir($root.'/app/Billing'))->toBeTrue();
});

it('shares nothing between payments and the platform\'s billing: no payment file names billing, no billing file names the payment port, and the two fakes do not name each other', function () {
    $root = dirname(__DIR__, 2);
    $paymentFakes = array_values(array_filter(
        array_map(fn (string $path): string => substr($path, strlen($root) + 1), sourceFilesUnder($root.'/tests/Support', ['php'])),
        fn (string $path): bool => str_contains($path, 'Payment'),
    ));
    $billingFakes = array_values(array_filter(
        array_map(fn (string $path): string => substr($path, strlen($root) + 1), sourceFilesUnder($root.'/tests/Support', ['php'])),
        fn (string $path): bool => str_contains($path, 'Billing'),
    ));

    expect($paymentFakes)->toBe(['tests/Support/FakePaymentAdapter.php', 'tests/Support/FakePaymentProvider.php', 'tests/Support/FakeStripePaymentTransport.php', 'tests/Support/NowherePaymentProvider.php', 'tests/Support/RecordingPaymentLog.php'])
        ->and($billingFakes)->not->toBe([])
        ->and(filesNaming('/App\\\\Billing|config\\(\'billing|BILLING_/', ['app/Application/Payment', 'app/Infrastructure']))->toBe([])
        ->and(pathsNaming('/App\\\\Billing|Tests\\\\Support\\\\[A-Za-z]*Billing|BILLING_/', [...$paymentFakes, 'config/payments.php', 'app/Infrastructure/PaymentFactory.php', 'app/Infrastructure/Db/PostgresPaymentConnections.php', 'app/Jobs/FlagPayment.php', 'app/Jobs/ExpireCheckout.php', 'app/Application/Event/BookingPaidLate.php', 'app/Notifications/AnnounceLatePayment.php', 'app/Jobs/SendLatePaymentNotice.php', 'app/Notifications/LatePaymentNotice.php']))->toBe([])
        ->and(filesNaming('/Application\\\\Payment|payments\\.|PAYMENT_/', ['app/Billing']))->toBe([])
        ->and(pathsNaming('/Application\\\\Payment|Tests\\\\Support\\\\[A-Za-z]*Payment|PAYMENT_/', [...$billingFakes, 'config/billing.php']))->toBe([]);
});

it('keeps the payment HTTP surface, the platform mode\'s middleware, request and controller and every importer of the payment port included, free of billing', function () {
    $root = dirname(__DIR__, 2);
    $paymentHttp = array_values(array_filter(
        array_map(fn (string $path): string => substr($path, strlen($root) + 1), sourceFilesUnder($root.'/app/Http', ['php'])),
        fn (string $path): bool => preg_match('/(Pay|Platform)[A-Za-z]*\.php$/', basename($path)) === 1
            || preg_match('/App\\\\(Application|Infrastructure)\\\\Payment/', (string) file_get_contents($root.'/'.$path)) === 1,
    ));

    expect($paymentHttp)->toContain('app/Http/Middleware/RequiresPlatformSignature.php', 'app/Http/Requests/PlatformConnectRequest.php', 'app/Http/Controllers/Admin/PaymentsController.php', 'app/Http/Middleware/RequiresPaymentSignature.php', 'app/Http/Controllers/PaymentWebhookController.php', 'app/Http/Controllers/Api/HoldsController.php')
        ->and(pathsNaming('/App\\\\Billing|Tests\\\\Support\\\\[A-Za-z]*Billing|config\\(\'billing|BILLING_/', $paymentHttp))->toBe([]);
});

it('gates nothing on the platform\'s fee: the files that read it are payment files asking no feature, entitlement or subscription state, and no gate names it', function () {
    $fee = '/PlatformFee|PlatformConfiguration|PlatformMode\\b|platform_fee|PAYMENT_PLATFORM_FEE/';
    $gate = '/App\\\\Billing|Entitlement|FeatureGate|RequiresActiveSubscription|SubscriptionState|subscription_state/';
    $wiring = 'app/Providers/AppServiceProvider.php';
    $readers = filesNaming($fee, ['app', 'bootstrap', 'config', 'database', 'routes', 'resources/js']);
    $gates = filesNaming($gate, ['app', 'bootstrap', 'routes']);
    $root = dirname(__DIR__, 2);
    $wired = array_values(array_filter(
        array_map('trim', file($root.'/'.$wiring) ?: []),
        fn (string $line): bool => preg_match($fee, $line) === 1,
    ));

    // Every file that reads the fee, its terms or the mode that holds them, each a payment file.
    expect($readers)->toBe([
        'app/Application/Payment/ConnectPlatformMode.php',
        'app/Application/Payment/PlatformConfiguration.php',
        'app/Application/Payment/PlatformFee.php',
        'app/Application/Payment/PlatformMode.php',
        'app/Application/Payment/Stripe/StripeAdapter.php',
        'app/Application/Payment/Stripe/StripePaymentProvider.php',
        'app/Http/Controllers/Admin/PaymentsController.php',
        'app/Http/PaymentsSection.php',
        'app/Http/Requests/PlatformConnectRequest.php',
        'app/Infrastructure/Payment/ConfiguredPlatform.php',
        'app/Infrastructure/Payment/PlatformWebhook.php',
        $wiring,
        'config/payments.php',
    ])
        // None of them asks a gate, the container's wiring aside, which binds both apart.
        ->and(pathsNaming($gate, array_values(array_diff($readers, [$wiring]))))->toBe([])
        // No gate reads the fee: the feature gate, the subscription middleware, billing, the nav.
        ->and($gates)->toContain('app/Http/FeatureGate.php', 'app/Http/Middleware/RequiresActiveSubscription.php', 'app/Billing/Entitlements.php', 'app/Billing/StoredEntitlementResolver.php', 'app/Http/AdminNav.php', 'routes/tenant.php')
        ->and(array_values(array_intersect($gates, $readers)))->toBe([$wiring])
        // The wiring names the fee's port once, handing platform mode its terms, and no gate there
        // reads it.
        ->and($wired)->toBe([
            'use App\\Application\\Payment\\PlatformConfiguration;',
            'use App\\Application\\Payment\\PlatformMode;',
            '$this->app->bind(PlatformMode::class, fn (Application $app): PlatformMode => $app->make(PlatformConfiguration::class)->read());',
        ]);
});
