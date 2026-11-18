<?php

namespace Tests\Browser;

use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use LogicException;
use Pest\Browser\Browsable;
use Pest\Browser\Drivers\LaravelHttpServer;
use Pest\Browser\ServerManager;
use Symfony\Component\HttpFoundation\Response;
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

    /** The style element that switches every transition and animation off. */
    private const string STILL = '<style>*, *::before, *::after, *::backdrop { transition: none !important; animation: none !important; }</style>';

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

        // The plugin's server keeps a connection open and, for an answer that names no length,
        // writes the head and then, in a second write, the mark that ends a body. An answer whose
        // status says it has no body is whole to a browser at the head's blank line, so the browser
        // may send its next request down the same connection before that mark lands, and then
        // reads the mark as the start of the next answer and fails the request. Asked to close
        // the connection, the server writes the head alone and ends there, and the browser opens
        // a new connection for what follows. It is asked on the status and not on the method,
        // because the application answers this way itself and not only to a preflight.
        Event::listen(RequestHandled::class, function (RequestHandled $handled): void {
            if (in_array($handled->response->getStatusCode(), [Response::HTTP_NO_CONTENT, Response::HTTP_RESET_CONTENT, Response::HTTP_NOT_MODIFIED], true)) {
                $handled->response->headers->set('Connection', 'close');
            }
        });

        // Motion is off in every page this suite serves, so what a test reads or saves is the
        // state a change lands in and never a frame on the way there. The browser plugin has no
        // reduced-motion switch, so every HTML answer carries a style element at the end of its
        // head that leaves no transition and no animation on any element or pseudo-element.
        // Only the tests are still: people using the admin see its motion.
        //
        // What that costs: a transition or an animation that never runs never ends, so the
        // browser fires no transitionend and no animationend, and a part that starts one and
        // waits to hear it finish waits for good. The calendar library is such a part: it glides
        // a block back from a drop it refuses itself and ends the drag only when that glide's
        // transition does. So a test here never drops on a target the calendar refuses and then
        // waits for the calendar to settle.
        Event::listen(RequestHandled::class, function (RequestHandled $handled): void {
            $content = $handled->response->getContent();
            $type = $handled->response->headers->get('Content-Type') ?? '';
            $head = is_string($content) ? strpos($content, '</head>') : false;

            if (str_starts_with($type, 'text/html') && is_string($content) && $head !== false) {
                $handled->response->setContent(substr_replace($content, self::STILL, $head, 0));
            }
        });
    }
}
