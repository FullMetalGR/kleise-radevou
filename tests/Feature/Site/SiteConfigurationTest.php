<?php

/*
 * The site's values: config/site.php holds each key from the environment with its default,
 * .env.example names every variable, and the platform's mails' sender name defaults to the
 * public name. The analytics tag's two values, sit in config/services.php beside the other
 * third parties': the measurement id, unset by default, and the tag's script address, Google's
 * by default. The configuration files are read here as the framework reads them, under an
 * environment the case sets, so the developer's own .env, which may name a sender or an id of
 * its own, decides nothing.
 */

/** The site's environment variables, the sender name's and the analytics tag's. */
const SITE_ENVIRONMENT = ['SITE_NAME', 'CONTACT_ADDRESS', 'ENTITY_NAME', 'ENTITY_ADDRESS', 'ENTITY_VAT', 'LEGAL_REVIEWED', 'MAIL_FROM_NAME', 'GA_MEASUREMENT_ID', 'GA_SCRIPT'];

/**
 * A configuration file's array as read under the environment given, every variable of
 * SITE_ENVIRONMENT it does not name unset for the length of the read and put back after.
 *
 * @param  array<mixed>  $environment
 * @return array<mixed>
 */
function siteConfigurationUnder(string $file, array $environment): array
{
    $saved = [];

    foreach (SITE_ENVIRONMENT as $name) {
        $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        $value = $environment[$name] ?? null;

        if (is_string($value)) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }
    }

    try {
        $configuration = require config_path($file);
    } finally {
        foreach ($saved as $name => [$server, $env, $put]) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            if ($env !== null) {
                $_ENV[$name] = $env;
            }

            if ($put !== false) {
                putenv($name.'='.$put);
            }
        }
    }

    assert(is_array($configuration));

    return $configuration;
}

it('holds the site\'s keys with their defaults when the environment names none', function () {
    expect(siteConfigurationUnder('site.php', []))->toBe([
        'name' => 'kleiseradevou.gr',
        'contact_address' => 'support@kleiseradevou.gr',
        'entity' => [
            'name' => 'ADS Solutions O.E.',
            'address' => 'Miltiadou 8, Nea Ionia, 142 31, Athens, Greece',
            'vat' => '',
        ],
        'legal_reviewed' => false,
        'demo_slug' => 'demo',
    ]);
});

it('reads each key from its environment variable', function () {
    expect(siteConfigurationUnder('site.php', [
        'SITE_NAME' => 'example.test',
        'CONTACT_ADDRESS' => 'help@example.test',
        'ENTITY_NAME' => 'Example Ltd',
        'ENTITY_ADDRESS' => '1 Example Street',
        'ENTITY_VAT' => 'EL000000000',
        'LEGAL_REVIEWED' => 'true',
    ]))->toBe([
        'name' => 'example.test',
        'contact_address' => 'help@example.test',
        'entity' => ['name' => 'Example Ltd', 'address' => '1 Example Street', 'vat' => 'EL000000000'],
        'legal_reviewed' => true,
        'demo_slug' => 'demo',
    ]);
});

it('names the mails\' sender with the public name unless the environment names a sender of its own', function () {
    $sender = function (array $environment): mixed {
        $from = siteConfigurationUnder('mail.php', $environment)['from'] ?? null;

        return is_array($from) ? $from['name'] ?? null : null;
    };

    expect($sender([]))->toBe(siteConfigurationUnder('site.php', [])['name'])
        ->and($sender(['SITE_NAME' => 'example.test']))->toBe('example.test')
        ->and($sender(['SITE_NAME' => 'example.test', 'MAIL_FROM_NAME' => 'Someone else']))->toBe('Someone else');
});

it('names every site variable in .env.example, and leaves the sender name to default to the public name', function () {
    $lines = preg_split('/\R/', (string) file_get_contents(base_path('.env.example'))) ?: [];
    $named = [];

    foreach ($lines as $line) {
        if (preg_match('/^([A-Z_]+)=/', $line, $match) === 1) {
            $named[] = $match[1];
        }
    }

    expect($named)->toContain('SITE_NAME', 'CONTACT_ADDRESS', 'ENTITY_NAME', 'ENTITY_ADDRESS', 'ENTITY_VAT', 'LEGAL_REVIEWED')
        ->not->toContain('MAIL_FROM_NAME');
});

it('holds the analytics tag\'s id unset and Google\'s gtag/js address when the environment names neither, and reads each from its variable', function () {
    $analytics = function (array $environment): mixed {
        $services = siteConfigurationUnder('services.php', $environment);

        return $services['google_analytics'] ?? null;
    };

    expect($analytics([]))->toBe(['measurement_id' => null, 'script' => 'https://www.googletagmanager.com/gtag/js'])
        ->and($analytics(['GA_MEASUREMENT_ID' => 'G-EXAMPLE123', 'GA_SCRIPT' => 'https://analytics.example.test/gtag/js']))
        ->toBe(['measurement_id' => 'G-EXAMPLE123', 'script' => 'https://analytics.example.test/gtag/js']);
});

it('names both analytics variables in .env.example, the id left empty so a copied file asks nothing', function () {
    $values = [];

    foreach (preg_split('/\R/', (string) file_get_contents(base_path('.env.example'))) ?: [] as $line) {
        if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $match) === 1) {
            $values[$match[1]] = $match[2];
        }
    }

    expect($values)->toHaveKey('GA_MEASUREMENT_ID')
        ->toHaveKey('GA_SCRIPT')
        ->and($values['GA_MEASUREMENT_ID'] ?? null)->toBe('')
        ->and($values['GA_SCRIPT'] ?? null)->toBe('https://www.googletagmanager.com/gtag/js');
});
