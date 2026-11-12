<?php

declare(strict_types=1);

namespace App\Infrastructure\Turnstile;

use App\Application\Turnstile\TurnstileCheck;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * The anti-spam check over the framework's HTTP client: one form-encoded POST to Cloudflare's
 * siteverify endpoint with the secret key, read from configuration at the call, the widget's
 * token and the address that posted it, the verdict being the answer's "success" when it is a
 * boolean. The request gives up after TIMEOUT seconds, connecting included, so a form never
 * waits longer on Cloudflare than that.
 *
 * More than one form runs the check, so its log lines name the form generically and never one
 * of them: the form whose check failed is refused, whichever it was.
 *
 * Every other ending is a failed check: an endpoint that cannot be reached, a timeout included,
 * logs UNREACHABLE and nothing of the exception, and an answer that carries no verdict logs
 * NO_VERDICT with its status alone; neither line carries the token or the secret. An unset
 * secret asks nothing, since a form checks nothing while a key is unset.
 *
 * A failed verdict whose "error-codes" list names invalid-input-secret names the platform's own
 * key: a secret Cloudflare does not know refuses every form that runs the check, so it logs
 * INVALID_SECRET at error, carrying nothing of the answer, the token or the secret, and the form
 * is refused as for any failed verdict. Every other code logs nothing.
 */
final readonly class HttpTurnstileCheck implements TurnstileCheck
{
    public const string ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const int TIMEOUT = 5;

    public const string UNREACHABLE = 'The anti-spam check could not reach Cloudflare; the form was refused.';

    public const string NO_VERDICT = 'The anti-spam check had no verdict from Cloudflare; the form was refused.';

    public const string INVALID_SECRET = 'Cloudflare refused the anti-spam check\'s secret key as invalid; the form was refused, and every form that runs the check is refused until the key is corrected or unset.';

    /** The documented "error-codes" value naming a secret Cloudflare does not know. */
    private const string SECRET_CODE = 'invalid-input-secret';

    public function __construct(private Factory $http, private Repository $config, private LoggerInterface $log) {}

    public function passes(#[SensitiveParameter] string $token, ?string $remoteAddress): bool
    {
        $secret = $this->config->get('services.turnstile.secret_key');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $form = ['secret' => $secret, 'response' => $token];

        if ($remoteAddress !== null) {
            $form['remoteip'] = $remoteAddress;
        }

        try {
            $response = $this->http->asForm()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::TIMEOUT)
                ->post(self::ENDPOINT, $form);
        } catch (ConnectionException) {
            $this->log->warning(self::UNREACHABLE);

            return false;
        }

        $verdict = $response->successful() ? $response->json('success') : null;

        if (! is_bool($verdict)) {
            $this->log->warning(self::NO_VERDICT, ['status' => $response->status()]);

            return false;
        }

        $codes = $response->json('error-codes');

        if (! $verdict && is_array($codes) && in_array(self::SECRET_CODE, $codes, true)) {
            $this->log->error(self::INVALID_SECRET);
        }

        return $verdict;
    }
}
