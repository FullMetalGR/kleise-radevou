<?php

declare(strict_types=1);

namespace App\Http;

use LogicException;

/**
 * The decision link this request presented and the guard accepted, for the page that runs after
 * it. The guard has already read the user and decided; a page reading the query string again
 * would be a second opinion on a credential, and two opinions on a credential differ the first
 * day either changes.
 *
 * Bound for exactly the span of one request, the way the tenant and the managed booking are:
 * the middleware binds it before the route runs and releases it after, whatever the route did,
 * so a process serving many requests in a row can never hand one request the link of an earlier
 * one. Reading it where nothing is bound is a programming error and is answered as one, because
 * the only way to reach a page that asks for it is through the guard that binds it.
 */
final class PresentedLink
{
    private ?SignedDecision $decision = null;

    public function bind(SignedDecision $decision): void
    {
        if ($this->decision !== null) {
            throw new LogicException(sprintf('A decision link is already bound for this request: booking "%s".', $this->decision->uuid));
        }

        $this->decision = $decision;
    }

    public function release(): void
    {
        $this->decision = null;
    }

    public function get(): SignedDecision
    {
        return $this->decision ?? throw new LogicException('No decision link is bound: this route does not run behind the decision link guard.');
    }
}
