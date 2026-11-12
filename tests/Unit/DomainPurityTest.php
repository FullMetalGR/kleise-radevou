<?php

/*
 * app/Domain is pure PHP: no framework import, no facade, no container, no Eloquent, and nothing
 * from the layers above it. The Unit suite never boots the application, so a framework reference
 * in there would surface only once something else loaded it. This reads the tokens of every file
 * under the directory instead: a qualified name outside the domain's own namespace or a call to
 * one of the framework's global helpers fails here, and a comment naming either does not.
 */

/**
 * @return list<string>
 */
function phpFilesUnder(string $directory): array
{
    $files = [];

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_dir($path)) {
            $files = [...$files, ...phpFilesUnder($path)];
        } elseif (str_ends_with($entry, '.php')) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

function isForeignNamespace(string $name): bool
{
    $root = strstr($name, '\\', true);

    if ($root === 'App') {
        return ! str_starts_with($name.'\\', 'App\\Domain\\');
    }

    return in_array($root, ['Illuminate', 'Laravel', 'Carbon', 'Symfony', 'Inertia', 'Livewire', 'Pest', 'Database', 'Tests'], true);
}

/**
 * Every reference in one source text that the domain may not make, in source order: a
 * qualified name in a foreign namespace, or a call to a framework helper. A method, a static
 * call or a declaration that happens to share a helper's name is not a call to the helper.
 *
 * @return list<string>
 */
function foreignReferences(string $source): array
{
    $helpers = [
        'app', 'resolve', 'config', 'env', 'now', 'today', 'event', 'dispatch', 'dispatch_sync',
        'request', 'response', 'view', 'route', 'url', 'logger', 'info', 'cache', 'session', 'auth',
        'abort', 'abort_if', 'abort_unless', 'trans', '__', 'collect', 'value', 'tap', 'once',
        'report', 'rescue', 'retry', 'validator', 'redirect', 'back', 'bcrypt', 'encrypt', 'decrypt',
        'broadcast', 'cookie', 'context', 'defer', 'fake', 'policy', 'old', 'asset', 'action',
        'base_path', 'app_path', 'config_path', 'database_path', 'public_path', 'resource_path',
        'storage_path', 'lang_path',
    ];

    $tokens = array_values(array_filter(
        PhpToken::tokenize($source),
        fn (PhpToken $token): bool => ! $token->isIgnorable(),
    ));

    $found = [];

    foreach ($tokens as $index => $token) {
        if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $name = ltrim($token->text, '\\');

            if (str_contains($name, '\\')) {
                if (isForeignNamespace($name)) {
                    $found[] = $name;
                }

                continue;
            }
        } elseif ($token->is(T_STRING)) {
            $name = $token->text;
        } else {
            continue;
        }

        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;

        if (! in_array($name, $helpers, true) || $next === null || ! $next->is('(')) {
            continue;
        }

        if ($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])) {
            continue;
        }

        $found[] = $name.'()';
    }

    return $found;
}

it('references no framework namespace, no upper layer and no framework helper anywhere under app/Domain', function () {
    $directory = dirname(__DIR__, 2).'/app/Domain';
    $files = phpFilesUnder($directory);

    expect($files)->not->toBeEmpty();

    $offences = [];

    foreach ($files as $path) {
        foreach (foreignReferences((string) file_get_contents($path)) as $reference) {
            $offences[] = substr($path, strlen($directory) + 1).': '.$reference;
        }
    }

    expect($offences)->toBe([]);
});

it('catches an import, an upper-layer class, a helper call and a fully qualified helper, and ignores a method, a declaration, a static call and a comment of the same names', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Domain\Sample;

    use App\Domain\Enum\PaymentMode;
    use DateTimeImmutable;
    use Illuminate\Support\Str;

    // config('app.name') and Illuminate\Support\Facades\DB in a comment are words, not calls.
    final class Sample extends App\Models\Tenant
    {
        public function config(): string
        {
            return $this->config().Sample::config();
        }

        public function run(): void
        {
            config('x');
            \app();
        }
    }
    PHP;

    expect(foreignReferences($source))->toBe(['Illuminate\Support\Str', 'App\Models\Tenant', 'config()', 'app()']);
});
