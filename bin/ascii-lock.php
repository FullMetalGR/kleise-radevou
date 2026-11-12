<?php

declare(strict_types=1);

/*
 * Rewrites the informational fields of composer.lock, package descriptions and author
 * names, to plain ASCII. Composer never reads those fields when it installs, and every
 * file in this repository is ASCII. Runs after every composer update; exits non-zero if
 * anything non-ASCII would remain.
 */

$path = __DIR__.'/../composer.lock';

if (! is_file($path)) {
    exit(0);
}

$original = (string) file_get_contents($path);
$lock = json_decode($original);

if (! $lock instanceof stdClass) {
    fwrite(STDERR, "composer.lock is not a JSON object.\n");
    exit(1);
}

$toAscii = function (string $value): string {
    if (class_exists(Normalizer::class)) {
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);

        if (is_string($decomposed)) {
            $value = $decomposed;
        }

        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
    }

    $value = preg_replace('/[^\x00-\x7F]+/', '', $value) ?? $value;

    return trim(preg_replace('/ {2,}/', ' ', $value) ?? $value);
};

foreach (['packages', 'packages-dev'] as $section) {
    $packages = $lock->{$section} ?? [];

    if (! is_array($packages)) {
        continue;
    }

    foreach ($packages as $package) {
        if (! $package instanceof stdClass) {
            continue;
        }

        if (isset($package->description) && is_string($package->description)) {
            $package->description = $toAscii($package->description);
        }

        if (! isset($package->authors) || ! is_array($package->authors)) {
            continue;
        }

        foreach ($package->authors as $author) {
            if ($author instanceof stdClass && isset($author->name) && is_string($author->name)) {
                $author->name = $toAscii($author->name);
            }
        }
    }
}

$rewritten = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

if (preg_match_all('/^.*[^\x00-\x7F].*$/m', $rewritten, $matches) > 0) {
    fwrite(STDERR, "composer.lock still carries non-ASCII bytes:\n".implode("\n", $matches[0])."\n");
    exit(1);
}

if ($rewritten !== $original) {
    file_put_contents($path, $rewritten);
    echo "composer.lock rewritten to ASCII.\n";
}
