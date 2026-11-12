<?php

declare(strict_types=1);

namespace App\Infrastructure\Map;

use Illuminate\Contracts\Config\Repository;

/**
 * The one Google Maps key, config services.google.maps_key from the environment, read at each
 * use so a test that sets or clears it is read. While it is unset the business page has no map:
 * the settings save writes none and dispatches nothing, and neither adapter asks Google
 * anything. The value goes into a request to Google and nowhere else, never a log line, an
 * answer or a queue payload.
 */
final readonly class GoogleMapsKey
{
    public function __construct(private Repository $config) {}

    public function isSet(): bool
    {
        return $this->value() !== null;
    }

    public function value(): ?string
    {
        $key = $this->config->get('services.google.maps_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
