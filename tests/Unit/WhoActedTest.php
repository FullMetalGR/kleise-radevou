<?php

use App\Application\Dto\Customer;
use App\Domain\Audit\Actor;
use App\Notifications\ActorShape;
use App\Notifications\BusinessDirectory;
use App\Notifications\TenantUser;
use App\Notifications\WhoActed;
use App\Tenancy\Role;
use Tests\Support\FixedDirectory;

/*
 * Who did a thing to a booking, as a notice to the business says it: the one place an actor on a
 * record becomes one of five shapes and a name, and the one place a user id becomes a name. It
 * answers no words: each shape is one whole sentence of the notice's own, in its recipient's
 * language, BusinessMailKeysTest holding the sentences to their keys.
 *
 * The trail names a user by id and nobody by name, so a name has to be read from the tenant's
 * users at the moment the notice goes. It is read only where one is needed: the customer is
 * named from the booking and the engine needs no lookup, and a directory that refuses to be read
 * is how the test knows.
 */

/**
 * A directory that must not be read, because the actor gives no reason to.
 */
final readonly class UnreadDirectory implements BusinessDirectory
{
    public function users(): array
    {
        throw new LogicException('Nobody should have read the users for this actor.');
    }

    public function staffOn(string $uuid): array
    {
        throw new LogicException('Nobody should have read the staff for this actor.');
    }
}

/**
 * The public name the platform's support is said in. The unit suite reads no configuration, so
 * the name is handed in as the notice jobs hand in config('site.name'); the business notice's own
 * test reads the configured one.
 */
const ACTED_PLATFORM = 'Example Bookings';

function actedCustomer(): Customer
{
    return new Customer('Casey Customer', 'casey@example.test', null);
}

function actedTeam(): BusinessDirectory
{
    return new FixedDirectory([
        new TenantUser(4, 'Demo Owner', 'owner@example.test', Role::Owner),
        new TenantUser(9, 'Marina Manager', 'marina@example.test', Role::Manager),
    ]);
}

it('names the customer from the booking and reads nothing, because the notice already carries who the customer is', function () {
    $who = WhoActed::of(Actor::customer(), actedCustomer(), new UnreadDirectory, ACTED_PLATFORM);

    expect($who->shape)->toBe(ActorShape::Customer)
        ->and($who->name)->toBe('Casey Customer')
        ->and($who->names())->toBe(['customer' => 'Casey Customer']);
});

it('names a user by the name on their row, read now rather than at the moment they acted', function () {
    $marina = WhoActed::of(Actor::user(9), actedCustomer(), actedTeam(), ACTED_PLATFORM);

    expect($marina->shape)->toBe(ActorShape::User)
        ->and($marina->names())->toBe(['staff' => 'Marina Manager'])
        ->and(WhoActed::of(Actor::user(4), actedCustomer(), actedTeam(), ACTED_PLATFORM)->names())->toBe(['staff' => 'Demo Owner']);
});

it('says so where the user the trail names is no longer one of the tenant\'s, rather than printing a number nobody can place', function () {
    $gone = WhoActed::of(Actor::user(77), actedCustomer(), actedTeam(), ACTED_PLATFORM);

    expect($gone->shape)->toBe(ActorShape::RemovedUser)
        ->and($gone->name)->toBeNull()
        ->and($gone->names())->toBe([]);
});

it('calls the engine and a job the system, and reads nothing for either, because neither is anybody at the business', function () {
    expect(WhoActed::of(Actor::system(), actedCustomer(), new UnreadDirectory, ACTED_PLATFORM)->shape)->toBe(ActorShape::System)
        ->and(WhoActed::of(Actor::job('sweeper'), actedCustomer(), new UnreadDirectory, ACTED_PLATFORM)->shape)->toBe(ActorShape::System)
        ->and(WhoActed::of(Actor::system(), actedCustomer(), new UnreadDirectory, ACTED_PLATFORM)->names())->toBe([]);
});

it('calls support acting in a session by the public name, and reads nothing, because the operator is nobody at the business and is named to it by no name', function () {
    $support = WhoActed::of(Actor::support(3), actedCustomer(), new UnreadDirectory, ACTED_PLATFORM);

    expect($support->shape)->toBe(ActorShape::Support)
        ->and($support->names())->toBe(['name' => 'Example Bookings'])
        ->and(WhoActed::of(Actor::support(3), actedCustomer(), new UnreadDirectory, 'bookings.example')->names())->toBe(['name' => 'bookings.example']);
});

it('tells the five shapes apart, one each, so a notice reads one whole sentence for whoever it names', function () {
    $shapes = array_map(fn (Actor $actor): ActorShape => WhoActed::of($actor, actedCustomer(), actedTeam(), ACTED_PLATFORM)->shape, [
        Actor::customer(),
        Actor::user(9),
        Actor::user(77),
        Actor::system(),
        Actor::support(3),
    ]);

    expect($shapes)->toBe([ActorShape::Customer, ActorShape::User, ActorShape::RemovedUser, ActorShape::System, ActorShape::Support])
        ->and(count(ActorShape::cases()))->toBe(5);
});
