<?php

use App\Models\User;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/*
 * The console command that stands in for a Users screen the admin does not have.
 */

/**
 * @param  array<string, string>  $input
 */
function addUserCommand(TestCase $test, array $input): PendingCommand
{
    $command = $test->artisan('tenant:add-user', $input);
    assert($command instanceof PendingCommand);

    return $command;
}

beforeEach(function () {
    provisionTenant('luna', ['ownerEmail' => 'owner@luna.example']);
    provisionTenant('pier', ['ownerEmail' => 'owner@pier.example']);
});

it('adds a user to a tenant with a name, an email, a password and a role', function () {
    addUserCommand($this, ['slug' => 'luna', '--name' => 'Maria Manager', '--email' => 'maria@luna.example', '--password' => 'a long password', '--role' => 'manager'])
        ->expectsOutputToContain('Added maria@luna.example to "luna" as manager.')
        ->assertExitCode(0);

    $user = User::query()->where('email', 'maria@luna.example')->sole();

    expect($user->name)->toBe('Maria Manager')
        ->and($user->role)->toBe(Role::Manager)
        ->and($user->tenant?->slug)->toBe('luna')
        ->and(Hash::check('a long password', $user->password))->toBeTrue();
});

it('refuses a role outside the three', function () {
    addUserCommand($this, ['slug' => 'luna', '--name' => 'X', '--email' => 'x@luna.example', '--password' => 'secret', '--role' => 'admin'])
        ->assertExitCode(2);

    expect(User::query()->where('email', 'x@luna.example')->exists())->toBeFalse();
});

it('refuses a second user with the same email at the same tenant, whatever its case', function () {
    addUserCommand($this, ['slug' => 'luna', '--name' => 'Twin', '--email' => 'Owner@Luna.example', '--password' => 'secret', '--role' => 'staff'])
        ->expectsOutputToContain('already has a user with that email')
        ->assertExitCode(2);

    expect(User::query()->where('name', 'Twin')->exists())->toBeFalse();
});

it('adds the same email at another tenant, since email is unique per tenant', function () {
    addUserCommand($this, ['slug' => 'pier', '--name' => 'Luna Owner At Pier', '--email' => 'owner@luna.example', '--password' => 'secret', '--role' => 'staff'])
        ->assertExitCode(0);
});

it('refuses an unknown tenant', function () {
    addUserCommand($this, ['slug' => 'nobody', '--name' => 'X', '--email' => 'x@example.com', '--password' => 'secret', '--role' => 'staff'])
        ->assertExitCode(2);
});

it('adds a user who can then sign in', function () {
    addUserCommand($this, ['slug' => 'luna', '--name' => 'Maria Manager', '--email' => 'maria@luna.example', '--password' => 'a long password', '--role' => 'manager'])
        ->assertExitCode(0);

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'maria@luna.example', 'password' => 'a long password'])
        ->assertRedirect(tenantUrl('luna', '/admin'));

    $this->assertAuthenticated();
});
