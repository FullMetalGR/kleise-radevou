<?php

use App\Domain\Availability\FreeBusyMask;
use App\Domain\Availability\Granularity;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\NotBookedMask;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository;

/*
 * The one place a reader and a writer agree on the key and the stored form of a staff-day's
 * not-booked mask: the key's five parts with an absent mutex row as its own value, the stored
 * form compact and its round trip exact, the lifetime one day or the earliest hold expiry
 * whichever comes first, and an entry past that expiry a miss whatever the store still holds.
 * Over the framework's array store and its null store, with no application booted.
 */

function maskAt(int $width): FreeBusyMask
{
    $mask = FreeBusyMask::allSet($width)->clear(0, 3)->clear($width - 5, $width);

    for ($index = 7; $index < $width; $index += 11) {
        $mask = $mask->clear($index, $index + 1);
    }

    return $mask;
}

function instantOf(string $text): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone('UTC'));
}

it('keys an entry by the tenant schema, the staff member, the day, the granularity and the revision, an absent mutex row as its own value distinct from zero', function () {
    $key = StaffDayMaskCache::key('tenant_luna', 12, '2026-04-06', Granularity::default(), 7);

    expect($key)->toBe('staff-day-mask:tenant_luna:12:2026-04-06:5:7')
        ->and(StaffDayMaskCache::key('tenant_luna', 12, '2026-04-06', Granularity::default(), null))->toBe('staff-day-mask:tenant_luna:12:2026-04-06:5:none')
        ->and(StaffDayMaskCache::key('tenant_luna', 12, '2026-04-06', Granularity::default(), 0))->toBe('staff-day-mask:tenant_luna:12:2026-04-06:5:0')
        ->and(StaffDayMaskCache::key('tenant_pier', 12, '2026-04-06', Granularity::default(), 7))->not->toBe($key)
        ->and(StaffDayMaskCache::key('tenant_luna', 12, '2026-04-06', Granularity::minutes(15), 7))->not->toBe($key)
        ->and(StaffDayMaskCache::key('tenant_luna', 12, '2026-04-07', Granularity::default(), 7))->not->toBe($key);
});

it('round-trips a mask through the stored form exactly at width {0}, with and without an earliest hold expiry, the expiry to the microsecond', function (int $width) {
    $mask = maskAt($width);
    $expiry = instantOf('2026-04-06 07:15:00.250001');

    $plain = StaffDayMaskCache::decode(StaffDayMaskCache::encode(new NotBookedMask($mask)), $width);
    $held = StaffDayMaskCache::decode(StaffDayMaskCache::encode(new NotBookedMask($mask, $expiry)), $width);

    expect($plain)->not->toBeNull()
        ->and($plain?->mask->equals($mask))->toBeTrue()
        ->and($plain?->earliestHoldExpiry)->toBeNull()
        ->and($held?->mask->equals($mask))->toBeTrue()
        ->and($held?->earliestHoldExpiry?->format('Y-m-d H:i:s.uP'))->toBe('2026-04-06 07:15:00.250001+00:00')
        ->and(strlen(StaffDayMaskCache::encode(new NotBookedMask($mask))))->toBeLessThanOrEqual(8 * intdiv($width + 63, 64) * 2)
        ->and(StaffDayMaskCache::encode(new NotBookedMask($mask)))->toMatch('/^[ -~]+$/');
})->with([[276], [288], [300], [64], [1440]]);

it('spells the stored form as the width, the words packed big-endian in base64, and the expiry, so a reader on any host reads a writer on any other', function () {
    expect(StaffDayMaskCache::encode(new NotBookedMask(FreeBusyMask::allSet(64), instantOf('2026-04-06 07:00:00'))))
        ->toBe('64;'.base64_encode(str_repeat("\xff", 8)).';2026-04-06 07:00:00.000000+00:00')
        ->and(StaffDayMaskCache::encode(new NotBookedMask(FreeBusyMask::allClear(288))))
        ->toBe('288;'.base64_encode(str_repeat("\x00", 40)).';');
});

it('treats a stored form of another width, a malformed one, one with a bit past the width, and one with an unreadable expiry as a miss', function (string $stored) {
    expect(StaffDayMaskCache::decode($stored, 288))->toBeNull();
})->with([
    'another width' => ['276;'.base64_encode(str_repeat("\xff", 32)."\x00\x00\x00\x00\x00\x0f\xff\xff").';'],
    'the words of another width under this one' => ['288;'.base64_encode(str_repeat("\xff", 40)).';'],
    'too few words' => ['288;'.base64_encode(str_repeat("\x00", 32)).';'],
    'not base64' => ['288;***;'],
    'no separators' => ['garbage'],
    'too many parts' => ['288;'.base64_encode(str_repeat("\x00", 40)).';;'],
    'an unreadable expiry' => ['288;'.base64_encode(str_repeat("\x00", 40)).';soon'],
    'empty' => [''],
]);

it('gives an entry a lifetime of one day, or the time until its earliest hold expiry when that comes first, and none once that expiry has passed', function () {
    $now = instantOf('2026-04-06 07:00:00');
    $mask = FreeBusyMask::allSet(288);

    expect(StaffDayMaskCache::LIFETIME_SECONDS)->toBe(86_400)
        ->and(StaffDayMaskCache::lifetime(new NotBookedMask($mask), $now))->toBe(86_400)
        ->and(StaffDayMaskCache::lifetime(new NotBookedMask($mask, instantOf('2026-04-08 07:00:00')), $now))->toBe(86_400)
        ->and(StaffDayMaskCache::lifetime(new NotBookedMask($mask, instantOf('2026-04-06 07:15:00')), $now))->toBe(900)
        ->and(StaffDayMaskCache::lifetime(new NotBookedMask($mask, instantOf('2026-04-06 07:00:00.500000')), $now))->toBe(0)
        ->and(StaffDayMaskCache::lifetime(new NotBookedMask($mask, instantOf('2026-04-06 07:00:00')), $now))->toBe(0)
        ->and(StaffDayMaskCache::lifetime(new NotBookedMask($mask, instantOf('2026-04-06 06:00:00')), $now))->toBe(0);
});

it('fetches hits and misses in one call over the array store, an entry whose earliest hold expiry has passed reading as a miss though the store still holds it', function () {
    $store = new Repository(new ArrayStore);
    $cache = new StaffDayMaskCache($store);
    $now = instantOf('2026-04-06 07:00:00');
    $later = instantOf('2026-04-06 07:20:00');

    $cache->store('a', new NotBookedMask(maskAt(288)), $now);
    $cache->store('b', new NotBookedMask(maskAt(276), instantOf('2026-04-06 07:15:00')), $now);
    $cache->store('c', new NotBookedMask(maskAt(300), instantOf('2026-04-06 06:59:59')), $now);

    $atNow = $cache->fetch(['a' => 288, 'b' => 276, 'c' => 300, 'd' => 288], $now);
    $atLater = $cache->fetch(['a' => 288, 'b' => 276, 'c' => 300, 'd' => 288], $later);

    expect(array_keys($atNow))->toBe(['a', 'b'])
        ->and($atNow['a']->mask->equals(maskAt(288)))->toBeTrue()
        ->and($atNow['b']->mask->equals(maskAt(276)))->toBeTrue()
        ->and($atNow['b']->earliestHoldExpiry?->format('H:i'))->toBe('07:15')
        ->and(array_keys($atLater))->toBe(['a'])
        ->and($store->has('b'))->toBeTrue()
        ->and($store->has('c'))->toBeFalse()
        ->and($cache->fetch([], $now))->toBe([])
        ->and($cache->fetch(['a' => 276], $now))->toBe([]);
});

it('reads nothing back from the null store and stores nothing in it', function () {
    $store = new Repository(new NullStore);
    $cache = new StaffDayMaskCache($store);
    $now = instantOf('2026-04-06 07:00:00');

    $cache->store('a', new NotBookedMask(maskAt(288)), $now);

    expect($cache->fetch(['a' => 288], $now))->toBe([])
        ->and($store->has('a'))->toBeFalse();
});
