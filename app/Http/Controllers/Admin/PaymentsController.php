<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Payment\ConnectionMode;
use App\Application\Payment\ConnectionModeTaken;
use App\Application\Payment\ConnectOwnMode;
use App\Application\Payment\ConnectPlatformMode;
use App\Application\Payment\PaymentConnections;
use App\Application\Payment\PlatformUnavailable;
use App\Application\Payment\TestConnection;
use App\Application\Payment\WebhookToken;
use App\Http\AdminSession;
use App\Http\ErrorEnvelope;
use App\Http\Requests\PaymentCredentialsRequest;
use App\Http\Requests\PlatformConnectRequest;
use App\Infrastructure\PaymentFactory;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The Payments section's writes on the Settings screen: saving own-mode credentials, which runs
 * the test call, Test connection, the webhook URL's rotation, the tenant's switch off and back
 * on, and Disconnect. Each is a configuration write under manageSettings and the subscription
 * gate, declared on its route, the save and the test, each a live call to the provider, also
 * under a limit of their own shared between the two, and each answers as PUT /admin/settings
 * answers, a redirect back with a flash, the state arriving through the page's props. The save
 * alone carries a body.
 *
 * A check's outcome lives on the row, the reason with it, so every later render reads it; the
 * flash only says whether the call passed and never carries what the provider said. A write
 * meeting no connection to act on is the framework's 404; a second Disconnect is a no-op, as a
 * second logo removal is. The second of two saves racing for the one primary row is refused as a
 * worded 409 through the envelope, never a 500.
 *
 * Every flash and refusal is a key of the payments section, worded in the request's language: a
 * mode taken by the other mode and the onboarding the platform cannot open are worded here from
 * what their exceptions carry, which hold no sentence of their own.
 */
final readonly class PaymentsController
{
    /** The section of lang/{locale}/admin the Payments section reads. */
    public const string SECTION = 'payments';

    /** The writes' flashes, keys of payments.php worded in the request's language. */
    public const string SAVED = 'admin/payments.flashes.saved';

    public const string SAVED_UNCHECKED = 'admin/payments.flashes.saved_unchecked';

    public const string TEST_PASSED = 'admin/payments.flashes.test_passed';

    public const string TEST_FAILED = 'admin/payments.flashes.test_failed';

    public const string ROTATED = 'admin/payments.flashes.rotated';

    public const string DISABLED = 'admin/payments.flashes.disabled';

    public const string ENABLED = 'admin/payments.flashes.enabled';

    public const string DISCONNECTED = 'admin/payments.flashes.disconnected';

    public const string ONBOARDING_FAILED = 'admin/payments.flashes.onboarding_failed';

    /** A log line, read by no user, so it stays English. */
    public const string ONBOARDING_FAILED_LOG = 'The payment provider failed while a tenant connected through us';

    public const string CONTENDED = 'connection_contended';

    public const string CONTENDED_DETAIL = 'admin/payments.refusals.contended';

    public function __construct(
        private CurrentTenant $current,
        private PaymentFactory $payments,
        private ConnectOwnMode $connect,
        private TestConnection $test,
        private ConnectPlatformMode $platform,
        private LoggerInterface $log,
    ) {}

    public function store(PaymentCredentialsRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $check = $this->connect->connect($this->connections(), $request->descriptor(), $request->fields());
        } catch (UniqueConstraintViolationException) {
            return (new ErrorEnvelope(self::CONTENDED, 409, PaymentCredentialsRequest::line(self::CONTENDED_DETAIL)))->toResponse();
        } catch (ConnectionModeTaken $taken) {
            return self::taken($taken);
        }

        return $check !== null && $check->ok
            ? $this->back($request, 'success', self::SAVED)
            : $this->back($request, 'error', self::SAVED_UNCHECKED);
    }

    /**
     * Platform mode's one button: the connected account opened, the connection written pending
     * with its routing row, and the owner sent to the provider's hosted onboarding, which
     * returns them to Settings. The answer is Inertia's external location: a 409 carrying
     * X-Inertia-Location to an Inertia visit, which the client follows as a full-page load, and
     * a plain redirect to anything else. A provider failing is logged by the exception's class
     * alone and answers the error flash; whatever it left is a pending row the section shows.
     */
    public function connect(PlatformConnectRequest $request): Response
    {
        $adapter = $request->adapter();
        try {
            $url = $this->platform->connect($this->connections(), $adapter, AdminSession::at($request, SettingsController::path()));
        } catch (ConnectionModeTaken $taken) {
            return self::taken($taken);
        } catch (UniqueConstraintViolationException) {
            return (new ErrorEnvelope(self::CONTENDED, 409, PaymentCredentialsRequest::line(self::CONTENDED_DETAIL)))->toResponse();
        } catch (Throwable $failure) {
            return $this->failed($request, $adapter->descriptor()->slug, $failure);
        }

        return Inertia::location($url);
    }

    /**
     * A fresh onboarding link for the platform connection's own account while it stands pending
     * or restricted, or active with information still due; the 404 where there is none, and a
     * worded 409 through the envelope where the platform's keys for its provider are unset, the
     * connection standing.
     */
    public function resume(Request $request): Response
    {
        try {
            $url = $this->platform->resume($this->connections(), AdminSession::at($request, SettingsController::path()));
        } catch (PlatformUnavailable) {
            return (new ErrorEnvelope(PlatformUnavailable::CODE, 409, PaymentCredentialsRequest::line(PlatformUnavailable::DETAIL)))->toResponse();
        } catch (Throwable $failure) {
            return $this->failed($request, $this->connections()->primary()->provider ?? '', $failure);
        }

        return Inertia::location($url ?? throw new NotFoundHttpException);
    }

    public function test(Request $request): RedirectResponse
    {
        $check = $this->test->test($this->connections()) ?? throw new NotFoundHttpException;

        return $check->ok
            ? $this->back($request, 'success', self::TEST_PASSED)
            : $this->back($request, 'error', self::TEST_FAILED);
    }

    public function rotate(Request $request): RedirectResponse
    {
        $this->connections()->rotateToken(WebhookToken::mint()) || throw new NotFoundHttpException;

        return $this->back($request, 'success', self::ROTATED);
    }

    public function disable(Request $request): RedirectResponse
    {
        $this->connections()->setEnabled(false) || throw new NotFoundHttpException;

        return $this->back($request, 'success', self::DISABLED);
    }

    public function enable(Request $request): RedirectResponse
    {
        $this->connections()->setEnabled(true) || throw new NotFoundHttpException;

        return $this->back($request, 'success', self::ENABLED);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->connections()->remove();

        return $this->back($request, 'success', self::DISCONNECTED);
    }

    private function failed(Request $request, string $provider, Throwable $failure): RedirectResponse
    {
        $this->log->error(self::ONBOARDING_FAILED_LOG, ['provider' => $provider, 'exception' => $failure::class]);

        return $this->back($request, 'error', self::ONBOARDING_FAILED);
    }

    /**
     * The 409 of a provider connected in the other mode, worded by the mode it is taken in.
     */
    private static function taken(ConnectionModeTaken $taken): JsonResponse
    {
        $key = $taken->mode === ConnectionMode::Own ? ConnectionModeTaken::OWN : ConnectionModeTaken::PLATFORM;

        return (new ErrorEnvelope(ConnectionModeTaken::CODE, 409, PaymentCredentialsRequest::line($key, ['provider' => $taken->name])))->toResponse();
    }

    private function connections(): PaymentConnections
    {
        return $this->payments->connections($this->current->get());
    }

    private function back(Request $request, string $kind, string $key): RedirectResponse
    {
        $request->session()->flash('flash', ['kind' => $kind, 'message' => PaymentCredentialsRequest::line($key)]);

        return redirect()->back(fallback: AdminSession::at($request, SettingsController::path()));
    }
}
