<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use App\Tenancy\TenantUrls;
use Pest\Browser\Api\Webpage;
use Tests\Browser\BrowserTestCase;

/**
 * The platform console as a browser reaches it: a URL on the console's host, console. under the
 * central host, which BrowserTestCase pins to localhost on the plugin's port, so the console is
 * console.localhost there and a browser resolves it to loopback with no DNS; and signing in
 * through the real form.
 *
 * Here rather than in a Pest file for the reason Admin is: a file's functions are global, and two
 * files declaring one helper is a fatal error the moment both load.
 */
final class Console
{
    public static function url(string $path = ''): string
    {
        return app(TenantUrls::class)->console($path);
    }

    /**
     * Opens the console's door and submits the form, and answers the page the browser is left on.
     * Waiting for what should follow is the caller's, since a refusal is a journey too. The page
     * comes back as the plugin's awaitable wrapper, which stands in for the webpage it wraps, so
     * the type is said in the docblock alone.
     *
     * @return Webpage
     */
    public static function signIn(BrowserTestCase $test, string $email, string $password)
    {
        return $test->visit(self::url('/login'))
            ->fill('email', $email)
            ->fill('password', $password)
            ->click('.admin-console-login-submit');
    }
}
