<?php

use App\Models\User;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The QR codes of the Links card, GET /admin/settings/links/page.svg and GET
 * /admin/settings/links/{kind}/{id}.svg: an SVG generated on the server, under manageSettings and
 * no subscription gate, a read, cached a day, and the framework's 404 for a kind or an id with no
 * link.
 *
 * What a code encodes is proven by decoding it as far as its modules: the SVG's one path is read
 * back cell by cell, even-odd, into the module matrix it draws, and that matrix must be the one
 * the encoder makes of the link spelled here, the hosted page's link tagged ch=qr, so a scan is
 * recorded as the qr channel. No QR decoder is at hand under vendor/, and the encoder is
 * deterministic, so two links drawing one matrix would be two links the scanner reads as one.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
});

function qrOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * @return TestResponse<Response>
 */
function qrGet(TestCase $test, string $path, ?User $user = null): TestResponse
{
    return $test->actingAs($user ?? qrOwner())->get(tenantUrl('luna', '/admin/settings/links'.$path));
}

/**
 * The module matrix an SVG draws, read back from its one path: every cell whose centre the path
 * covers under the even-odd rule is dark. The path is in module units with the quiet zone outside
 * it, so cell (x, y) is the unit square at (x, y).
 *
 * @return list<list<bool>>
 */
function qrModulesOf(string $svg, int $size): array
{
    expect(preg_match_all('/<path\b[^>]*\sd="([^"]+)"/', $svg, $paths))->toBe(1);
    preg_match_all('/([MLZ])([^MLZ]*)/', $paths[1][0], $commands, PREG_SET_ORDER);

    $edges = [];
    $start = null;
    $at = null;

    foreach ($commands as $command) {
        $numbers = array_map(intval(...), preg_split('/[\s,]+/', trim($command[2]), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $point = count($numbers) === 2 ? [$numbers[0], $numbers[1]] : null;

        match ($command[1]) {
            'M' => [$start, $at] = [$point, $point],
            'L' => [$edges[], $at] = [[$at, $point], $point],
            'Z' => [$edges[], $at] = [[$at, $start], $start],
        };
    }

    $modules = [];

    for ($y = 0; $y < $size; $y++) {
        $row = [];

        for ($x = 0; $x < $size; $x++) {
            $crossings = 0;

            foreach ($edges as [$from, $to]) {
                // A ray from the cell's centre to the right crosses each vertical edge beside it
                // that spans the centre's height; horizontal edges are never crossed by it.
                if ($from !== null && $to !== null && $from[0] === $to[0] && $from[0] > $x + 0.5 && min($from[1], $to[1]) <= $y + 0.5 && $y + 0.5 < max($from[1], $to[1])) {
                    $crossings++;
                }
            }

            $row[] = $crossings % 2 === 1;
        }

        $modules[] = $row;
    }

    return $modules;
}

/**
 * The module matrix of the QR code a link encodes, as the encoder makes it at the level the route
 * draws at.
 *
 * @return array{list<list<bool>>, int}
 */
function qrMatrixOf(string $link): array
{
    $matrix = Encoder::encode($link, ErrorCorrectionLevel::M())->getMatrix();
    $modules = [];

    for ($y = 0; $y < $matrix->getHeight(); $y++) {
        $row = [];

        for ($x = 0; $x < $matrix->getWidth(); $x++) {
            $row[] = $matrix->get($x, $y) === 1;
        }

        $modules[] = $row;
    }

    return [$modules, $matrix->getWidth()];
}

it('draws the booking page\'s link tagged qr as an SVG, cached a day', function () {
    $response = qrGet($this, '/page.svg')->assertOk();
    [$expected, $size] = qrMatrixOf(tenantUrl('luna', '/?ch=qr'));
    $svg = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toBe('image/svg+xml')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=86400')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($svg)->toStartWith('<?xml')
        ->and(preg_match('/[^\x00-\x7F]/', $svg))->toBe(0)
        ->and(qrModulesOf($svg, $size))->toBe($expected)
        // A matrix of another link is not this one, which is what makes the comparison a proof.
        ->and(qrModulesOf($svg, $size))->not->toBe(qrMatrixOf(tenantUrl('luna', '/?ch=ig'))[0]);
});

it('draws a {kind}\'s link tagged qr', function (string $kind, string $table, string $name) {
    $id = LunaFixture::id($table, $name);
    $svg = (string) qrGet($this, '/'.$kind.'/'.$id.'.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->getContent();
    [$expected, $size] = qrMatrixOf(tenantUrl('luna', '/?'.$kind.'='.$id.'&ch=qr'));

    expect(qrModulesOf($svg, $size))->toBe($expected);
})->with([
    ['service', 'services', 'Haircut'],
    ['staff', 'staff', 'Elena Papadaki'],
]);

it('answers a service that is not offered online or not active too: the link is the link, and the flow judges it', function () {
    $id = LunaFixture::id('services', 'Haircut');
    DB::table('tenant_luna.services')->where('id', $id)->update(['online' => false, 'status' => 'inactive']);

    qrGet($this, '/service/'.$id.'.svg')->assertOk();
});

it('answers 404 for {what}', function (Closure $path) {
    $resolved = $path();
    assert(is_string($resolved));

    qrGet($this, $resolved)->assertNotFound();
})->with([
    'an id no service has' => [fn () => '/service/999999.svg'],
    'an id no member has' => [fn () => '/staff/999999.svg'],
    'the implicit member, who stands for the business' => [fn () => '/staff/'.implicitStaffId('tenant_luna').'.svg'],
    'a kind with no link' => [fn () => '/room/1.svg'],
    'an id that is not a number' => [fn () => '/service/abc.svg'],
    'no extension' => [fn () => '/service/1'],
]);

it('refuses a signed-in user without manageSettings and sends a guest to sign in', function () {
    $staff = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', 'Elena Papadaki'));

    qrGet($this, '/page.svg', $staff)->assertForbidden();
    qrGet($this, '/service/'.LunaFixture::id('services', 'Haircut').'.svg', $staff)->assertForbidden();

    auth()->logout();
    $this->get(tenantUrl('luna', '/admin/settings/links/page.svg'))->assertRedirect();
});

it('answers a lapsed tenant all the same, since a read is never behind the subscription gate', function () {
    billingSet(['subscription_state' => 'canceled']);

    qrGet($this, '/page.svg')->assertOk();
    qrGet($this, '/staff/'.LunaFixture::id('staff', 'Elena Papadaki').'.svg')->assertOk();
});
