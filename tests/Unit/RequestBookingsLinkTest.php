<?php

use App\Application\MyBookings\BookingsLinkRequested;
use App\Application\MyBookings\CustomerLinks;
use App\Application\MyBookings\LinkAnnouncer;
use App\Application\MyBookings\RequestBookingsLink;
use Tests\Support\FixedClock;

/*
 * The My bookings link's decision, over fakes: a link is minted and announced for an address with
 * bookings while the tenant sends the key, and nothing happens otherwise.
 */

/**
 * The two ports as one recorder: the addresses with bookings it answers yes for, and what was
 * asked, minted and announced, each in its own list and all of them in one order.
 */
final class RecordingLinks implements CustomerLinks, LinkAnnouncer
{
    /** @var list<string> */
    public array $order = [];

    /** @var list<array{email: string, hash: string, created: string, expires: string}> */
    public array $minted = [];

    /** @var list<BookingsLinkRequested> */
    public array $announced = [];

    /** @param  list<string>  $known */
    public function __construct(private array $known = []) {}

    public function hasBookings(string $email): bool
    {
        $this->order[] = 'asked '.$email;

        return in_array($email, $this->known, true);
    }

    public function mint(string $email, string $tokenHash, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt): int
    {
        $this->order[] = 'minted';
        $this->minted[] = ['email' => $email, 'hash' => $tokenHash, 'created' => $createdAt->format(DATE_ATOM), 'expires' => $expiresAt->format(DATE_ATOM)];

        return 42;
    }

    public function announce(BookingsLinkRequested $requested): void
    {
        $this->order[] = 'announced';
        $this->announced[] = $requested;
    }
}

function linkRequest(RecordingLinks $links, bool $on = true): RequestBookingsLink
{
    return new RequestBookingsLink($links, FixedClock::at('2026-11-02 06:00:00'), $links, fn (): bool => $on);
}

it('mints a link of fifteen minutes from the clock for an address with bookings, storing the hash, and announces the id with the plaintext once and the request\'s language', function () {
    $links = new RecordingLinks(['casey@example.com']);

    linkRequest($links)->request('casey@example.com', 'el');

    $announced = $links->announced[0] ?? null;

    expect($links->order)->toBe(['asked casey@example.com', 'minted', 'announced'])
        ->and($announced)->toBeInstanceOf(BookingsLinkRequested::class)
        ->and($announced?->linkId)->toBe(42)
        ->and($announced?->token)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($announced?->language)->toBe('el')
        ->and($links->minted)->toBe([[
            'email' => 'casey@example.com',
            'hash' => hash('sha256', (string) $announced?->token),
            'created' => '2026-11-02T06:00:00+00:00',
            'expires' => '2026-11-02T06:15:00+00:00',
        ]]);
});

it('mints nothing and announces nothing for an address with no bookings', function () {
    $links = new RecordingLinks(['casey@example.com']);

    linkRequest($links)->request('nobody@example.com', 'en');

    expect($links->order)->toBe(['asked nobody@example.com']);
});

it('asks nothing, mints nothing and announces nothing while the tenant has the bookings link switched off', function () {
    $links = new RecordingLinks(['casey@example.com']);

    linkRequest($links, on: false)->request('casey@example.com', 'en');

    expect($links->order)->toBe([]);
});
