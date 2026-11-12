<?php

use App\Tenancy\InvalidSlug;
use App\Tenancy\Slug;
use App\Tenancy\SlugProblem;
use App\Tenancy\SlugRules;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function slugProblem(string $input): ?SlugProblem
{
    try {
        app(SlugRules::class)->check($input);
    } catch (InvalidSlug $e) {
        return $e->problem;
    }

    return null;
}

it('lists every label the platform needs for itself as reserved', function (string $label) {
    expect(config('tenancy.reserved_slugs'))->toContain($label);
})->with([
    'www', 'api', 'app', 'admin', 'mail', 'smtp', 'static', 'cdn', 'assets', 'status', 'help',
    'docs', 'support', 'billing', 'blog', 'dashboard', 'login', 'auth', 'webhook', 'webhooks',
    // The console's form for a free tenant, GET /tenants/new, would otherwise be one tenant's
    // detail.
    'new',
]);

it('keeps every reserved label a valid slug, so the list cannot hide a typo', function () {
    /** @var list<string> $reserved */
    $reserved = config('tenancy.reserved_slugs');

    foreach ($reserved as $label) {
        expect(Slug::of($label)->value)->toBe($label);
    }
});

it('accepts a well-formed, unreserved, unused slug and returns it lowercased', function () {
    expect(app(SlugRules::class)->check('Acme-Salon'))->toEqual(Slug::of('acme-salon'));
});

it('refuses a reserved label', function () {
    expect(slugProblem('api'))->toBe(SlugProblem::Reserved);
});

it('refuses a reserved label whatever its case', function () {
    expect(slugProblem('API'))->toBe(SlugProblem::Reserved);
});

it('refuses a duplicate that differs only in case', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    expect(slugProblem('ACME'))->toBe(SlugProblem::Taken);
});

it('refuses a duplicate of a slug stored with upper case', function () {
    // Nothing writes an upper-case slug through the model, but citext makes the check hold
    // even for a row that reached the table some other way.
    $tenant = Tenant::factory()->create(['slug' => 'acme-2']);
    Tenant::query()->whereKey($tenant->id)->update(['slug' => 'ACME-2']);

    expect(slugProblem('acme-2'))->toBe(SlugProblem::Taken);
});

it('applies the shape rules before the reserved and taken ones', function () {
    expect(slugProblem('-api'))->toBe(SlugProblem::LeadingHyphen)
        ->and(slugProblem('api_v2'))->toBe(SlugProblem::InvalidCharacter);
});

it('says whether a slug is reserved without judging anything else, for the tenant resolver', function () {
    $rules = app(SlugRules::class);
    Tenant::factory()->create(['slug' => 'acme']);

    expect($rules->isReserved(Slug::of('api')))->toBeTrue()
        ->and($rules->isReserved(Slug::of('API')))->toBeTrue()
        ->and($rules->isReserved(Slug::of('acme')))->toBeFalse()
        ->and($rules->isReserved(Slug::of('nobody')))->toBeFalse();
});
