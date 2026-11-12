<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Contracts\Config\Repository;

/**
 * Whether Cloudflare Turnstile guards the two forms that carry it, the signup form and the site's
 * contact form: on while both keys under services.turnstile are set, off while either is unset,
 * when neither form renders a widget and neither POST runs a check, so a development or a test
 * signup or message goes through as it did before. The secret key is only asked whether it is set
 * here; the check reads it at the call.
 *
 * The widget's props, the site key and the address of the form's script fixed beside the keys,
 * go to the two forms' pages alone: no other page reads them, so no other page loads
 * Cloudflare's script. The token comes back in a field of its own, FIELD, the same on both forms,
 * which is never flashed back with the input, and no longer than TOKEN_MAX: a longer field is
 * refused by each form before the check is asked.
 */
final readonly class TurnstileGate
{
    public const string FIELD = 'turnstile_token';

    /** The longest token Cloudflare's widget issues. */
    public const int TOKEN_MAX = 2048;

    public function __construct(private Repository $config) {}

    public function on(): bool
    {
        return $this->filled('site_key') !== null && $this->filled('secret_key') !== null;
    }

    /**
     * The widget's site key and the address of the script the form given loads, while both keys
     * are set; null while either is unset.
     *
     * @return array{siteKey: string, script: string}|null
     */
    public function widget(TurnstileForm $form): ?array
    {
        $siteKey = $this->filled('site_key');
        $script = $this->filled($form->scriptKey());

        return $this->on() && $siteKey !== null && $script !== null ? ['siteKey' => $siteKey, 'script' => $script] : null;
    }

    private function filled(string $key): ?string
    {
        $value = $this->config->get('services.turnstile.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
