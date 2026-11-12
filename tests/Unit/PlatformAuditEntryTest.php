<?php

use App\Platform\PlatformAuditAction;
use App\Platform\PlatformAuditEntry;
use App\Platform\PlatformAuditKey;

/*
 * One row of the platform audit log before the writer has it: the action from the closed
 * vocabulary, and a payload whose every key is a case of PlatformAuditKey and whose every value
 * is a scalar or a list of scalars, as the tenant trail's AuditEntry checks its own. Anything
 * else is refused here, before a row exists, since the net over every test only reads what
 * reached the table.
 */

it('holds the vocabulary\'s twelve actions and four keys, the console\'s close and reopen among them', function () {
    expect(array_map(fn (PlatformAuditAction $action): string => $action->value, PlatformAuditAction::cases()))->toBe([
        'sign_in', 'sign_out', 'tenant_suspended', 'tenant_resumed', 'tenant_created',
        'features_changed', 'password_link_sent', 'support_opened', 'support_ended', 'contact_archived',
        'tenant_closed', 'tenant_reopened',
    ])->and(array_map(fn (PlatformAuditKey $key): string => $key->value, PlatformAuditKey::cases()))->toBe(['reason', 'features', 'user', 'message']);
});

it('keeps an action with no facts as an empty payload', function () {
    $entry = new PlatformAuditEntry(PlatformAuditAction::SignIn);

    expect($entry->action)->toBe(PlatformAuditAction::SignIn)
        ->and($entry->payload)->toBe([]);
});

it('keeps a payload of declared keys holding {case}', function (array $payload) {
    expect((new PlatformAuditEntry(PlatformAuditAction::FeaturesChanged, $payload))->payload)->toBe($payload);
})->with([
    'a string' => [['reason' => 'Unpaid since March']],
    'an integer' => [['user' => 42]],
    'a list of strings' => [['features' => ['staff']]],
    'an empty list' => [['features' => []]],
    'a float and a boolean in a list' => [['features' => [1.5, true]]],
    'every key at once' => [['reason' => 'Asked to', 'features' => ['staff'], 'user' => 7, 'message' => 3]],
]);

it('keeps an archived contact message as its id under message and nothing else', function () {
    $entry = new PlatformAuditEntry(PlatformAuditAction::ContactArchived, ['message' => 42]);

    expect($entry->action->value)->toBe('contact_archived')
        ->and($entry->payload)->toBe(['message' => 42]);
});

it('refuses a payload key outside the declared list: {case}', function (array $payload) {
    expect(fn () => new PlatformAuditEntry(PlatformAuditAction::SignIn, $payload))
        ->toThrow(InvalidArgumentException::class, 'is outside the declared list: reason, features, user, message.');
})->with([
    'an email' => [['email' => 'pat@inbox.example']],
    'a name' => [['name' => 'Pat Operator']],
    'a tenant, which is a column' => [['tenant' => 3]],
    'a list key' => [[0 => 'staff']],
]);

it('refuses a payload value that is not a scalar or a list of scalars: {case}', function (mixed $value) {
    expect(fn () => new PlatformAuditEntry(PlatformAuditAction::FeaturesChanged, ['features' => $value]))
        ->toThrow(InvalidArgumentException::class, 'The platform audit payload value under "features" is');
})->with([
    'null' => [null],
    'a map' => [['staff' => true]],
    'a list holding a list' => [[['staff']]],
    'a list holding null' => [['staff', null]],
    'an object' => [new stdClass],
    'an enum' => [PlatformAuditKey::Features],
    'an instant' => [new DateTimeImmutable('@0', new DateTimeZone('UTC'))],
]);
