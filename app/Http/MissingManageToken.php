<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * A request to a route behind the manage token that presents no token at all, in either of its
 * two transports. It is a malformed request rather than a failed credential: nothing has been
 * compared, so it is 400 invalid_request and never the guard's one 404, which would tell a
 * client with a truncated link that the booking is gone.
 *
 * A type of its own rather than a validation failure, because the credential is read in
 * middleware and a route's guard cannot wait for the form request validation lives in: the
 * validator's exception would also redirect with an error bag rather than answer, the day this
 * guard sits on a route that is not JSON. Not an HTTP exception either, because the envelope
 * words those from their status alone and this one has a sentence of its own to say.
 */
final class MissingManageToken extends RuntimeException
{
    public static function notPresented(): self
    {
        return new self('A request to a route behind the manage token carried no token.');
    }
}
