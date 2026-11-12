<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The two forms Cloudflare Turnstile guards: the signup form and the site's contact form. Each
 * loads the widget's script from an address fixed in configuration beside the keys, and the two
 * addresses differ in how the widget is drawn. The signup is a React page that renders the widget
 * itself, so its script waits to be asked, explicit rendering; the contact page is Blade with no
 * script of the site's own, so its script draws the widget from the markup it finds, implicit
 * rendering, the token posted in the field the markup names.
 */
enum TurnstileForm: string
{
    case Signup = 'signup';

    case Contact = 'contact';

    /**
     * The key under services.turnstile of the address this form loads the widget's script from.
     */
    public function scriptKey(): string
    {
        return match ($this) {
            self::Signup => 'script',
            self::Contact => 'page_script',
        };
    }
}
