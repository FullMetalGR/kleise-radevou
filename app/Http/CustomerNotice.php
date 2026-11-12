<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Translation\Translator;

/**
 * The one sentence a redirect carries to the customer's next page on the tenant's host, through
 * the framework's flash: My bookings after its form, its link and its sign-out, and after the pay
 * hop the page the customer reads the booking on, the manage page, My bookings, or the payment
 * link's status page. Flashed, so the page that follows reads it once and the framework clears it
 * after that page's answer; a reload reads nothing. A kind and the words, the shape every flash
 * of this codebase has.
 *
 * The words ride the flash as their key of pages.php, never as a sentence, and the page that
 * shows them reads the key through the translator in its own language, the locale SetTenantLocale
 * set for its request, so a redirect between a bare and a prefixed address cannot carry the wrong
 * language; a key the file lacks reads as itself, as the translator answers it.
 *
 * It holds a key written here and never a credential: the token and the session's secret are in
 * no key a caller hands it, and the session it rides is one whose previous URL the routes
 * carrying a credential never write (KeepLinkTokensOutOfTheSession).
 */
final readonly class CustomerNotice
{
    /** The flash's key, as the signup's pending page and the admin read theirs. */
    public const string KEY = 'flash';

    public function __construct(private Session $session, private Translator $translator) {}

    /**
     * @param  'success'|'info'|'warning'  $kind
     * @param  string  $key  the sentence's key of pages.php
     */
    public function flash(string $kind, string $key): void
    {
        $this->session->flash(self::KEY, ['kind' => $kind, 'message' => $key]);
    }

    /**
     * The sentence a redirect flashed, where one came, in the language of the page reading it.
     */
    public function read(): ?string
    {
        $flash = $this->session->get(self::KEY);
        $key = is_array($flash) ? ($flash['message'] ?? null) : null;

        if (! is_string($key)) {
            return null;
        }

        $sentence = $this->translator->get($key);

        return is_string($sentence) ? $sentence : $key;
    }
}
