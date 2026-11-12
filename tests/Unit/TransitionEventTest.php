<?php

use App\Application\Event\BookingConfirmed;
use App\Application\Event\BookingHeld;
use App\Application\Event\BookingRescheduled;
use App\Application\Event\CarriesTheTransition;
use App\Application\Event\TransitionEvent;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;

/*
 * The shape of the records a committed transition announces, held to what a listener can
 * actually subscribe to.
 *
 * The dispatcher resolves a listener by the event's own class and by the interfaces it
 * implements, and never by its parents. So a shared parent class would be a name a listener
 * could write, a subscription that matched nothing, and no error either way. The records that
 * share their five fields share them through a trait for that reason, and this holds them to
 * it: a base class reintroduced under them fails here rather than in a listener that quietly
 * never runs.
 *
 * Three records have a shape of their own. The move carries the two instants instead of a from
 * and a to, because a move leaves the status where it found it and the start it left is
 * unreadable once the old items are gone. The hold and the confirmation carry the plaintext
 * manage token, because those are the two moments it exists and the customer's email link is
 * its only durable copy. So the shared-shape assertion runs over the records that use the
 * trait, the token assertion over the two that carry one, and the two that matter, the marker
 * and the absent parent, run over all of them.
 */

/**
 * Every record under app/Application/Event: the classes, the interfaces and the trait left out.
 *
 * @return list<class-string>
 */
function transitionRecords(): array
{
    $records = [];

    foreach (scandir(dirname(__DIR__, 2).'/app/Application/Event') ?: [] as $entry) {
        if (! str_ends_with($entry, '.php')) {
            continue;
        }

        /** @var class-string $name */
        $name = 'App\\Application\\Event\\'.substr($entry, 0, -4);

        if (! class_exists($name)) {
            continue;
        }

        $records[] = $name;
    }

    sort($records);

    return $records;
}

/**
 * The records built from the shared shape, which is every one but the three with news of
 * their own.
 *
 * @return list<class-string>
 */
function sharedShapeRecords(): array
{
    return array_values(array_filter(
        transitionRecords(),
        fn (string $record): bool => in_array(CarriesTheTransition::class, class_uses($record) ?: [], true),
    ));
}

it('is every record a committed write announces, a transition or the late payment that moves nothing, and nothing else', function () {
    expect(array_map(fn (string $name): string => substr($name, strrpos($name, '\\') + 1), transitionRecords()))->toBe([
        'BookingApproved',
        'BookingCancelled',
        'BookingCompleted',
        'BookingConfirmed',
        'BookingExpired',
        'BookingHeld',
        'BookingNoShow',
        'BookingPaidLate',
        'BookingRejected',
        'BookingReleased',
        'BookingRescheduled',
    ]);
});

it('gives every record the marker to be taken by, and no parent for a listener to subscribe to and miss', function (string $record) {
    /** @var class-string $record */
    expect(class_implements($record))->toHaveKey(TransitionEvent::class)
        // The one that matters. A parent here would be a name a listener could write and a
        // subscription the dispatcher would never match, silently.
        ->and(class_parents($record))->toBe([])
        ->and((new ReflectionClass($record))->isFinal())->toBeTrue();
})->with(fn () => transitionRecords());

it('carries the same five facts in the same order on every record built from the shared shape, which is what the shape is for', function (string $record) {
    /** @var class-string $record */
    $constructor = (new ReflectionClass($record))->getConstructor();

    expect($constructor)->not->toBeNull()
        ->and(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructor?->getParameters() ?? []))
        ->toBe(['uuid', 'from', 'to', 'holdClass', 'actor']);
})->with(fn () => sharedShapeRecords());

it('builds every record but three from that shape, and each of the three from the news it alone has to carry', function () {
    // The move's own news is the two starts, and the session it cleared from a booking still
    // waiting for its payment, optional, and whether the customer is told, last and true unless
    // the business left them untold.
    expect(array_map(fn (string $name): string => substr($name, strrpos($name, '\\') + 1), sharedShapeRecords()))
        ->toHaveCount(count(transitionRecords()) - 3)
        ->not->toContain('BookingRescheduled')
        ->not->toContain('BookingHeld')
        ->not->toContain('BookingConfirmed')
        ->and(array_map(
            fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionClass(BookingRescheduled::class))->getConstructor()?->getParameters() ?? [],
        ))->toBe(['uuid', 'status', 'holdClass', 'actor', 'movedFrom', 'movedTo', 'session', 'tellCustomer']);
});

it('carries the plaintext manage token on the two records whose transition mints or verifies one, last and optional', function (string $record) {
    /** @var class-string $record */
    $defaults = [];

    foreach ((new ReflectionClass($record))->getConstructor()?->getParameters() ?? [] as $parameter) {
        $defaults[$parameter->getName()] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : 'required';
    }

    expect(array_keys($defaults))->toBe([...['uuid', 'from', 'to', 'holdClass', 'actor', 'manageToken'], ...($record === BookingConfirmed::class ? ['afterPayment'] : [])])
        // Optional, because a record built anywhere the plaintext does not exist must not be
        // obliged to invent one. A confirmation says it followed a payment only when it did.
        ->and($defaults)->toHaveKey('manageToken', null)
        ->and($defaults['afterPayment'] ?? false)->toBeFalse();
})->with([BookingHeld::class, BookingConfirmed::class]);

it('carries it nowhere else, because a plaintext credential on a record nothing needs it on is a credential in one more queue', function (string $record) {
    /** @var class-string $record */
    $names = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionClass($record))->getConstructor()?->getParameters() ?? [],
    );

    expect($names)->not->toContain('manageToken');
})->with(fn () => array_values(array_diff(transitionRecords(), [BookingHeld::class, BookingConfirmed::class])));

it('leaves the token null where the transition has none to carry, which is every call but the two', function () {
    $held = new BookingHeld('e1f4a9d0-0000-4000-8000-000000000001', null, BookingStatus::AwaitingApproval, HoldClass::Approval, Actor::customer());

    expect($held->manageToken)->toBeNull()
        ->and((new BookingHeld('e1f4a9d0-0000-4000-8000-000000000001', null, BookingStatus::Pending, HoldClass::Checkout, Actor::customer(), 'abc'))->manageToken)
        ->toBe('abc');
});
