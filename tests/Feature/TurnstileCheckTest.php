<?php

declare(strict_types=1);

use App\Application\Turnstile\TurnstileCheck;
use App\Infrastructure\Turnstile\HttpTurnstileCheck;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Tests\Support\SeamLog;

/*
 * The anti-spam check's adapter: one form-encoded POST to Cloudflare's siteverify endpoint
 * carrying the secret key, read from configuration at the call, the widget's token and the
 * address that posted it, given up after five seconds; the verdict is the documented answer's
 * "success", true or false, beside its "error-codes". The framework's client is faked with stray
 * requests refused, so no case opens a connection, and no line any case logs carries the secret
 * or the token.
 */

const TURNSTILE_CHECK_SECRET = '0x4AAAAAAATurnstileSecretNeverShown';
const TURNSTILE_CHECK_TOKEN = 'XXXX.DUMMY.TOKEN.NeverLogged';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.turnstile.secret_key' => TURNSTILE_CHECK_SECRET]);
    $this->log = new SeamLog;
    app()->instance(LoggerInterface::class, $this->log);
});

afterEach(function () {
    expect($this->log->text())->not->toContain(TURNSTILE_CHECK_SECRET)
        ->and($this->log->text())->not->toContain(TURNSTILE_CHECK_TOKEN);
});

/**
 * Fakes the endpoint with one answer and keeps the options the request was sent with.
 *
 * @param  array<array-key, mixed>  $body
 * @param  array<string, mixed>  $options
 */
function turnstileAnswers(array $body, array &$options, int $status = 200): void
{
    Http::fake([HttpTurnstileCheck::ENDPOINT => function (Request $request, array $sent) use ($body, $status, &$options): PromiseInterface {
        $options = $sent;

        return Http::response($body, $status);
    }]);
}

it('is the port the container answers with', function () {
    expect(get_class(app()->make(TurnstileCheck::class)))->toBe(HttpTurnstileCheck::class);
});

it('passes a token the endpoint answers success true for, posting the secret, the token and the address as one form inside five seconds', function () {
    $options = [];
    turnstileAnswers(['success' => true, 'hostname' => 'kleiseradevou.test', 'error-codes' => [], 'action' => '', 'cdata' => ''], $options);

    expect(app(HttpTurnstileCheck::class)->passes(TURNSTILE_CHECK_TOKEN, '203.0.113.7'))->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        && $request->isForm()
        && $request->data() === ['secret' => TURNSTILE_CHECK_SECRET, 'response' => TURNSTILE_CHECK_TOKEN, 'remoteip' => '203.0.113.7']);
    expect($options['timeout'] ?? null)->toBe(5)
        ->and($options['connect_timeout'] ?? null)->toBe(5)
        ->and($this->log->records)->toBe([]);
});

it('leaves the address out when the request had none', function () {
    $options = [];
    turnstileAnswers(['success' => true, 'error-codes' => []], $options);

    expect(app(HttpTurnstileCheck::class)->passes(TURNSTILE_CHECK_TOKEN, null))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->data() === ['secret' => TURNSTILE_CHECK_SECRET, 'response' => TURNSTILE_CHECK_TOKEN]);
});

it('fails a token the endpoint answers success false for, whatever its error codes but the secret\'s, logging nothing', function (mixed $codes) {
    $options = [];
    turnstileAnswers(['success' => false, 'error-codes' => $codes], $options);

    expect(app(HttpTurnstileCheck::class)->passes(TURNSTILE_CHECK_TOKEN, '203.0.113.7'))->toBeFalse()
        ->and($this->log->records)->toBe([]);
    Http::assertSentCount(1);
})->with([
    'an invalid token' => [['invalid-input-response']],
    'a token used before or expired' => [['timeout-or-duplicate']],
    'a token missing' => [['missing-input-response']],
    'a malformed request' => [['bad-request']],
    'an error on Cloudflare\'s side' => [['internal-error']],
    'no codes at all' => [[]],
    'codes that are not a list' => ['invalid-input-secret'],
]);

it('fails a token the endpoint answers success false for naming the secret invalid, logging one error line with neither the secret nor the token', function (array $codes) {
    $options = [];
    turnstileAnswers(['success' => false, 'error-codes' => $codes], $options);

    expect(app(HttpTurnstileCheck::class)->passes(TURNSTILE_CHECK_TOKEN, '203.0.113.7'))->toBeFalse()
        ->and($this->log->records)->toBe([['level' => 'error', 'message' => HttpTurnstileCheck::INVALID_SECRET, 'context' => []]]);
    Http::assertSentCount(1);
})->with([
    'the secret alone' => [['invalid-input-secret']],
    'the secret beside the token' => [['invalid-input-response', 'invalid-input-secret']],
]);

it('fails an answer that carries no verdict, logging one line with the status and nothing posted', function (int $status, array $body) {
    $options = [];
    turnstileAnswers($body, $options, $status);

    expect(app(HttpTurnstileCheck::class)->passes(TURNSTILE_CHECK_TOKEN, '203.0.113.7'))->toBeFalse()
        ->and($this->log->records)->toBe([['level' => 'warning', 'message' => HttpTurnstileCheck::NO_VERDICT, 'context' => ['status' => $status]]]);
})->with([
    'a server error' => [500, []],
    'a success that is not a boolean' => [200, ['success' => 'true']],
]);

it('fails when the endpoint cannot be reached, a timeout included, logging one line with no token, no secret and nothing of the exception', function () {
    Http::fake([HttpTurnstileCheck::ENDPOINT => fn () => throw new ConnectionException('cURL error 28: Operation timed out; response='.TURNSTILE_CHECK_TOKEN.'&secret='.TURNSTILE_CHECK_SECRET)]);

    expect(app(HttpTurnstileCheck::class)->passes(TURNSTILE_CHECK_TOKEN, '203.0.113.7'))->toBeFalse()
        ->and($this->log->records)->toBe([['level' => 'warning', 'message' => HttpTurnstileCheck::UNREACHABLE, 'context' => []]]);
});

it('words its three log lines for any form that runs the check, naming no one form', function () {
    expect(HttpTurnstileCheck::UNREACHABLE)->toBe('The anti-spam check could not reach Cloudflare; the form was refused.')
        ->and(HttpTurnstileCheck::NO_VERDICT)->toBe('The anti-spam check had no verdict from Cloudflare; the form was refused.')
        ->and(HttpTurnstileCheck::INVALID_SECRET)->toBe('Cloudflare refused the anti-spam check\'s secret key as invalid; the form was refused, and every form that runs the check is refused until the key is corrected or unset.');

    foreach ([HttpTurnstileCheck::UNREACHABLE, HttpTurnstileCheck::NO_VERDICT, HttpTurnstileCheck::INVALID_SECRET] as $line) {
        expect(strtolower($line))->not->toContain('signup')->not->toContain('contact');
    }
});

it('fails without asking anything while the secret key is unset', function (?string $secret) {
    config(['services.turnstile.secret_key' => $secret]);

    expect(app(HttpTurnstileCheck::class)->passes(TURNSTILE_CHECK_TOKEN, '203.0.113.7'))->toBeFalse();
    Http::assertNothingSent();
})->with([
    'null' => [null],
    'empty' => [''],
]);
