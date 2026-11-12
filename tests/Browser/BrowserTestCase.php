<?php

namespace Tests\Browser;

use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Pest\Browser\Browsable;
use Pest\Browser\Drivers\LaravelHttpServer;
use Pest\Browser\ServerManager;
use Tests\TestCase;

/**
 * Migrates once per process and truncates every table between tests, never wrapping a test in
 * a transaction, for the reason the Concurrency suite refuses to: the application the browser
 * talks to runs in this process on this connection, and a request that opens a transaction of
 * its own must not find one already open. Tenant schemas sit outside the truncation, and every
 * file drops the ones it provisions.
 *
 * The browser plugin serves the application on 127.0.0.1 at a free port, and once a test starts
 * it points app.url at that address, after this method has run. That address has no subdomains,
 * and every tenant page is a subdomain of the central host, so the central host is pinned here
 * to localhost at the plugin's port: a browser resolves {slug}.localhost to loopback with no DNS,
 * and the request arrives carrying the label the resolver reads.
 *
 * The admin signs in over a session, so the session driver is the database here; see setUp.
 *
 * The plugin applies its trait to every test at run time; it is used here as well so that the
 * static analysis sees visit() on the test case.
 */
abstract class BrowserTestCase extends TestCase
{
    use Browsable;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        foreach ([RefreshDatabase::class, DatabaseTransactions::class] as $trait) {
            if (in_array($trait, class_uses_recursive($this), true)) {
                throw new LogicException(sprintf('%s wraps each test in a transaction; the Browser suite truncates instead.', $trait));
            }
        }

        parent::setUp();

        $http = ServerManager::instance()->http();

        if (! $http instanceof LaravelHttpServer) {
            throw new LogicException('The browser plugin serves no application; the Browser suite needs one.');
        }

        $this->app->instance(TenantUrls::class, new TenantUrls(sprintf('http://localhost:%d', $http->port)));

        // The admin's session has to live from one browser request to the next, which the array
        // driver phpunit.xml sets does not give. The central sessions table does, and the
        // truncation between tests empties it with everything else.
        config(['session.driver' => 'database']);
    }
}
