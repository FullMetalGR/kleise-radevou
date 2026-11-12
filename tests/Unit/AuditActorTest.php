<?php

use App\Domain\Audit\Actor;

/*
 * Who acted, in the five shapes audit_log.actor allows: customer, system, user:{id},
 * job:{name} and support:{operator id}. The value exists so a use case names the actor
 * rather than spelling the column, and so the one place that decides what an actor may look
 * like is here and in the CHECK that mirrors it. A name or an email is not an actor, in any
 * shape.
 */

it('spells the customer and the system as the bare words', function () {
    expect(Actor::customer()->value)->toBe('customer')
        ->and(Actor::system()->value)->toBe('system')
        ->and((string) Actor::customer())->toBe('customer');
});

it('knows the guest from the business, which is the one thing a policy asks of an actor', function () {
    expect(Actor::customer()->isCustomer())->toBeTrue()
        ->and(Actor::user(1)->isCustomer())->toBeFalse()
        ->and(Actor::system()->isCustomer())->toBeFalse()
        ->and(Actor::job('expire-holds')->isCustomer())->toBeFalse()
        ->and(Actor::support(3)->isCustomer())->toBeFalse()
        ->and(Actor::of('customer')->isCustomer())->toBeTrue();
});

it('spells a user as user: and the digits of their id', function () {
    expect(Actor::user(1)->value)->toBe('user:1')
        ->and(Actor::user(90210)->value)->toBe('user:90210');
});

it('spells a support operator as support: and the digits of the operator\'s id', function () {
    expect(Actor::support(1)->value)->toBe('support:1')
        ->and(Actor::support(4021)->value)->toBe('support:4021')
        ->and((string) Actor::support(3))->toBe('support:3');
});

it('refuses an operator id that is not a positive integer', function (int $id) {
    expect(fn () => Actor::support($id))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

it('gives back the operator of a support actor and nothing for the other four, and no user id for support, so approved_by stays null as it does for the sweeper', function () {
    expect(Actor::support(3)->supportOperatorId())->toBe(3)
        ->and(Actor::of('support:4021')->supportOperatorId())->toBe(4021)
        ->and(Actor::support(3)->userId())->toBeNull()
        ->and(Actor::of('support:3')->userId())->toBeNull()
        ->and(Actor::user(3)->supportOperatorId())->toBeNull()
        ->and(Actor::customer()->supportOperatorId())->toBeNull()
        ->and(Actor::system()->supportOperatorId())->toBeNull()
        ->and(Actor::job('expire-holds')->supportOperatorId())->toBeNull();
});

it('gives back the id of a user actor and nothing at all for the other four, so a column that references a user is written from the actor rather than from a second argument that could disagree with it', function () {
    expect(Actor::user(7)->userId())->toBe(7)
        ->and(Actor::user(90210)->userId())->toBe(90210)
        ->and(Actor::of('user:7')->userId())->toBe(7)
        ->and(Actor::customer()->userId())->toBeNull()
        ->and(Actor::system()->userId())->toBeNull()
        ->and(Actor::job('expire-holds')->userId())->toBeNull()
        ->and(Actor::support(7)->userId())->toBeNull();
});

it('refuses a user id that is not a positive integer', function (int $id) {
    expect(fn () => Actor::user($id))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

it('spells a job as job: and its name', function () {
    expect(Actor::job('expire-holds')->value)->toBe('job:expire-holds')
        ->and(Actor::job('send_reminder')->value)->toBe('job:send_reminder');
});

it('refuses the job name {name}, which is not a bare lowercase name', function (string $name) {
    expect(fn () => Actor::job($name))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'an email' => ['casey@example.com'],
    'capitals' => ['ExpireHolds'],
    'a space' => ['expire holds'],
    'already prefixed' => ['job:expire-holds'],
    'leading digit' => ['1-expire'],
    'a trailing newline' => ["expire\n"],
]);

it('reads back the shape {value} it wrote', function (string $value) {
    expect(Actor::of($value)->value)->toBe($value);
})->with(['customer', 'system', 'user:7', 'job:expire-holds', 'support:3']);

it('refuses to read {value} back as an actor', function (string $value) {
    expect(fn () => Actor::of($value))->toThrow(InvalidArgumentException::class);
})->with([
    'an email' => ['casey@example.com'],
    'a name' => ['Casey Customer'],
    'a user with no id' => ['user:'],
    'a user named rather than numbered' => ['user:casey'],
    'a job with an address for a name' => ['job:casey@example.com'],
    'the empty string' => [''],
    'a capitalised word' => ['Customer'],
    'a customer with something after it' => ['customer:1'],
    'a user with a trailing newline' => ["user:1\n"],
    'a job with a trailing newline' => ["job:expire-holds\n"],
    'the word customer with a trailing newline' => ["customer\n"],
    'a user with a leading newline' => ["\nuser:1"],
    'two users on two lines' => ["user:1\nuser:2"],
    'support with no operator id' => ['support:'],
    'support named rather than numbered' => ['support:pat'],
    'support with an address for an operator' => ['support:pat@inbox.example'],
    'support with a trailing newline' => ["support:1\n"],
    'support with a leading newline' => ["\nsupport:1"],
    'the bare word support' => ['support'],
]);

it('carries the shapes the actor CHECK is written from, anchored at both ends', function () {
    expect(Actor::USER_SHAPE)->toBe('^user:[0-9]+$')
        ->and(Actor::JOB_SHAPE)->toBe('^job:[a-z][a-z0-9_-]*$')
        ->and(Actor::SUPPORT_SHAPE)->toBe('^support:[0-9]+$');
});

it('reads those shapes as the CHECK reads them, so the dollar means the end of the string and not the line before a trailing newline', function () {
    // The two patterns are one text read by two engines. PCRE lets a final newline sit after
    // the dollar and the POSIX operator the CHECK uses does not, so a class that matched them
    // unmodified would accept a row the database then refused, while both patterns still read
    // the same. tests/Feature/TenantSchemaTest holds the two to each other on what they accept.
    expect(preg_match('/'.Actor::USER_SHAPE.'/', "user:1\n"))->toBe(1)
        ->and(preg_match('/'.Actor::USER_SHAPE.'/D', "user:1\n"))->toBe(0)
        ->and(fn () => Actor::of("user:1\n"))->toThrow(InvalidArgumentException::class)
        ->and(preg_match('/'.Actor::SUPPORT_SHAPE.'/', "support:1\n"))->toBe(1)
        ->and(preg_match('/'.Actor::SUPPORT_SHAPE.'/D', "support:1\n"))->toBe(0)
        ->and(fn () => Actor::of("support:1\n"))->toThrow(InvalidArgumentException::class);
});
