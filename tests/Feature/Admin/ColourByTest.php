<?php

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Requests\ColourByRequest;
use App\Infrastructure\Db\SettingsReader;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * Colour by: one word on the settings row, written by PUT /admin/settings/colour-by from the
 * Calendar's filter card under manageSettings and the subscription gate, another word refused as
 * validation, and read by every screen through the shared settings prop.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
});

function colourOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * The write as the filter card sends it, the body as given, a dataset's malformed ones included.
 *
 * @param  array<mixed>  $body
 * @return TestResponse<Response>
 */
function colourWrite(TestCase $test, User $user, array $body): TestResponse
{
    return $test->actingAs($user)->from(tenantUrl('luna', '/admin'))->json('PUT', tenantUrl('luna', '/admin/settings/colour-by'), $body, ['X-Inertia' => 'true']);
}

it('writes the one word and answers a redirect back with its flash, the shared prop carrying it on every screen', function (string $word) {
    $response = colourWrite($this, colourOwner(), ['colour_by' => $word]);

    $response->assertRedirect(tenantUrl('luna', '/admin'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => __(SettingsController::COLOUR_BY_SAVED)]);
    expect(settingsOf('luna')->value('colour_by'))->toBe($word)
        ->and(app(SettingsReader::class)->read(LunaFixture::tenant())->colourBy)->toBe($word);

    foreach (['/admin', '/admin/bookings', '/admin/staff'] as $path) {
        $this->actingAs(colourOwner())->get(tenantUrl('luna', $path))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('settings.colour_by', $word));
    }
})->with(['category', 'status', 'staff']);

it('refuses another word, a missing one and a non-string as validation, writing nothing', function (array $body) {
    settingsOf('luna')->update(['colour_by' => 'status']);

    colourWrite($this, colourOwner(), $body)
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.detail', adminLine(ColourByRequest::REFUSED));
    expect(settingsOf('luna')->value('colour_by'))->toBe('status');
})->with([
    'another word' => [['colour_by' => 'service']],
    'a capitalised word' => [['colour_by' => 'Staff']],
    'no word' => [[]],
    'a list' => [['colour_by' => ['staff']]],
]);

it('words the refusal in the user\'s language from the calendar section\'s validation group, the Greek file\'s own line for a user at el and the English for a colleague at en', function (SiteLanguage $language) {
    expect(ColourByRequest::REFUSED)->toStartWith('admin/calendar.validation.')
        ->and(app('translator')->has(ColourByRequest::REFUSED, $language->value, false))->toBeTrue()
        ->and(adminLine(ColourByRequest::REFUSED, [], SiteLanguage::Greek))->not->toBe(adminLine(ColourByRequest::REFUSED));

    colourOwner()->forceFill(['locale' => $language->value])->save();

    colourWrite($this, colourOwner(), ['colour_by' => 'service'])
        ->assertStatus(400)
        ->assertJsonPath('data.detail', adminLine(ColourByRequest::REFUSED, [], $language));
})->with(['a user at en' => [SiteLanguage::English], 'a user at el' => [SiteLanguage::Greek]]);

it('opens the write to a manager, refuses a staff user, and gates it by the subscription as a configuration write', function () {
    $manager = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    colourWrite($this, $manager, ['colour_by' => 'category'])->assertRedirect(tenantUrl('luna', '/admin'));
    expect(settingsOf('luna')->value('colour_by'))->toBe('category');

    $staff = User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff'));
    colourWrite($this, $staff, ['colour_by' => 'status'])->assertForbidden();
    expect(settingsOf('luna')->value('colour_by'))->toBe('category');

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    colourWrite($this, colourOwner(), ['colour_by' => 'status'])->assertForbidden();
    expect(settingsOf('luna')->value('colour_by'))->toBe('category');
});
