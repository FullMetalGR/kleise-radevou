<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Tenancy\Role;
use App\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Adds a user to a tenant. It stands in for a Users screen the admin does not have.
 *
 * A user belongs to exactly one tenant, and email is unique per tenant rather than globally, so
 * the same address may sign in at two businesses and never twice at one. The comparison is the
 * column's own, citext, so an address differing only in case is the same address.
 */
final class AddTenantUserCommand extends Command
{
    protected $signature = 'tenant:add-user
        {slug : The tenant the user belongs to}
        {--name= : The user\'s name}
        {--email= : The user\'s email, their login at this tenant}
        {--password= : The user\'s initial password}
        {--role= : owner, manager or staff}';

    protected $description = 'Add a user to a tenant with a name, an email, a password and a role.';

    public function handle(): int
    {
        $input = [
            'slug' => $this->text($this->argument('slug')),
            'name' => $this->text($this->option('name')),
            'email' => $this->text($this->option('email')),
            'password' => $this->text($this->option('password')),
            'role' => $this->text($this->option('role')),
        ];

        $validator = Validator::make($input, [
            'slug' => ['required', 'string'],
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'role' => ['required', Rule::enum(Role::class)],
        ], [
            'role.enum' => 'The role must be one of owner, manager or staff.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        $tenant = Tenant::query()->where('slug', $input['slug'])->first();

        if ($tenant === null) {
            $this->error(sprintf('No tenant is called "%s".', $input['slug']));

            return self::INVALID;
        }

        if (User::query()->where('tenant_id', $tenant->id)->where('email', $input['email'])->exists()) {
            $this->error(sprintf('Tenant "%s" already has a user with that email.', $tenant->slug));

            return self::INVALID;
        }

        User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => Role::from($input['role']),
        ]);

        $this->info(sprintf('Added %s to "%s" as %s.', $input['email'], $tenant->slug, $input['role']));

        return self::SUCCESS;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
