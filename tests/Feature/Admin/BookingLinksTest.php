<?php

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LunaFixture;

pest()->use(RefreshDatabase::class);

/*
 * The booking links the admin hands out: every one spelled on the server, so the Copy booking
 * link buttons of the service and staff detail pages and the Settings screen's Links card can
 * only give out the link the hosted page reads, and the card's rows are what the public catalogue
 * offers.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
});

function linksOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * The ids of a table's rows a query keeps, in the catalogue's order, each with the link the
 * hosted page reads for it.
 *
 * @return list<array{id: int, link: string}>
 */
function linksOf(string $kind, Builder $rows): array
{
    $links = [];

    foreach ($rows->orderBy('sort')->orderBy('id')->pluck('id')->all() as $id) {
        assert(is_int($id));

        $links[] = ['id' => $id, 'link' => tenantUrl('luna', '/?'.$kind.'='.$id)];
    }

    return $links;
}

/**
 * The Links card's rows of a kind, as the server should hand them, from the rows a query keeps.
 *
 * @return list<array{id: int, name: string, link: string, qr: string}>
 */
function linksCardRows(string $kind, Builder $rows): array
{
    $card = [];

    foreach ($rows->orderBy('sort')->orderBy('id')->get(['id', 'name'])->all() as $row) {
        assert(is_int($row->id) && is_string($row->name));

        $card[] = ['id' => $row->id, 'name' => $row->name, 'link' => tenantUrl('luna', '/?'.$kind.'='.$row->id), 'qr' => '/admin/settings/links/'.$kind.'/'.$row->id.'.svg'];
    }

    return $card;
}

it('hands the Services page every service\'s link, offered online or not, active or not', function () {
    $haircut = LunaFixture::id('services', 'Haircut');
    DB::table('tenant_luna.services')->where('id', $haircut)->update(['online' => false, 'status' => 'inactive']);
    $expected = linksOf('service', DB::table('tenant_luna.services'));

    $this->actingAs(linksOwner())->get(tenantUrl('luna', '/admin/services/'.$haircut))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Services')->where('links', $expected));
});

it('hands the Staff page every named member\'s link and none for the implicit member', function () {
    $expected = linksOf('staff', DB::table('tenant_luna.staff')->where('is_implicit', false));

    expect($expected)->not->toBe([]);

    $this->actingAs(linksOwner())->get(tenantUrl('luna', '/admin/staff'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Staff')->where('links', $expected));
});

it('hands the Settings screen the Links card: the page\'s link and one row per offered service and member, each with its QR code\'s path', function () {
    $haircut = LunaFixture::id('services', 'Haircut');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    DB::table('tenant_luna.services')->where('id', LunaFixture::id('services', 'Colour'))->update(['online' => false]);
    DB::table('tenant_luna.services')->where('id', LunaFixture::id('services', 'Beard trim'))->update(['status' => 'inactive']);
    DB::table('tenant_luna.staff')->where('id', $nikos)->update(['online' => false]);

    $services = linksCardRows('service', DB::table('tenant_luna.services')->where('online', true)->where('status', 'active'));
    $staff = linksCardRows('staff', DB::table('tenant_luna.staff')->where('online', true)->where('status', 'active')->where('is_implicit', false));

    $this->actingAs(linksOwner())->get(tenantUrl('luna', '/admin/settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Settings')
            ->where('links.page', ['link' => tenantUrl('luna', '/'), 'qr' => '/admin/settings/links/page.svg'])
            ->where('links.services', $services)
            ->where('links.staff', $staff));

    expect(array_column($services, 'id'))->toContain($haircut)
        ->not->toContain(LunaFixture::id('services', 'Colour'))
        ->not->toContain(LunaFixture::id('services', 'Beard trim'))
        ->and(array_column($staff, 'id'))->not->toContain($nikos)->not->toContain(implicitStaffId('tenant_luna'))
        ->and($staff)->not->toBe([]);
});

it('spells the business page\'s offers with the same link the copy buttons hand out', function () {
    $haircut = LunaFixture::id('services', 'Haircut');

    expect((string) $this->get(tenantUrl('luna', '/'))->assertOk()->getContent())
        ->toContain(json_encode(tenantUrl('luna', '/?service='.$haircut), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: 'unencodable');
});
