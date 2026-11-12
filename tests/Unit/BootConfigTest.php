<?php

use App\Http\BootConfig;
use App\Site\SiteLanguage;

it('answers exactly its seven keys, in order, important_info null where unset', function () {
    $config = new BootConfig(tenantName: 'Acme Salon', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: null, defaultLanguage: SiteLanguage::English, language: SiteLanguage::English);

    expect($config->toArray())->toBe([
        'tenant_name' => 'Acme Salon',
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
        'granularity_min' => 5,
        'important_info' => null,
        'default_language' => 'en',
        'language' => 'en',
    ]);
});

it('carries the tenant\'s important info as the fifth key where it is set', function () {
    $config = new BootConfig(tenantName: 'Acme Salon', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: 'Please arrive five minutes early.', defaultLanguage: SiteLanguage::English, language: SiteLanguage::English);

    expect($config->toArray())->toHaveKey('important_info', 'Please arrive five minutes early.');
});

it('carries the tenant default and the request\'s language as the sixth and seventh keys, each the language\'s code, apart from one another', function () {
    $config = new BootConfig(tenantName: 'Acme Salon', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: null, defaultLanguage: SiteLanguage::Greek, language: SiteLanguage::English);

    expect(array_slice($config->toArray(), 5))->toBe(['default_language' => 'el', 'language' => 'en'])
        ->and(json_decode($config->toInlineJson(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['default_language' => 'el', 'language' => 'en']);
});

it('inlines as JSON that cannot close the script element it sits in', function () {
    $config = new BootConfig(tenantName: 'Acme </script><script>alert(1)</script> & "co"', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: 'Bring <b>cash</b> & "change"', defaultLanguage: SiteLanguage::English, language: SiteLanguage::Greek);

    $json = $config->toInlineJson();

    expect($json)->not->toContain('<')
        ->not->toContain('>')
        ->not->toContain('&')
        ->and(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->toBe($config->toArray());
});
