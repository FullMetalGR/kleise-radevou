<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Validation\Rule;

/**
 * The body of POST /admin/team: the name, the address and one of the three roles. The address is
 * refused where a user of this tenant signs in with it already, compared as the column compares
 * it, without regard to case; another tenant's user with the same address is no obstacle, an
 * address being unique per tenant and not across them. Every refusal is a key of admin/team.php's
 * validation, read in the request's language.
 */
final class TeamInviteRequest extends AdminWriteRequest
{
    /** The longest name the form takes. */
    public const int NAME_MAX = 200;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(CurrentTenant $current): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::NAME_MAX],
            'email' => ['required', 'string', 'email', Rule::unique('users', 'email')->where('tenant_id', $current->get()->id)],
            'role' => TeamRoleRequest::ROLE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.*' => StaffRequest::line('admin/team.validation.name'),
            'email.unique' => StaffRequest::line('admin/team.validation.email_taken'),
            'email.*' => StaffRequest::line('admin/team.validation.email'),
            'role.*' => StaffRequest::line(TeamRoleRequest::REFUSED),
        ];
    }

    public function name(): string
    {
        return trim($this->string('name')->toString());
    }

    public function email(): string
    {
        return trim($this->string('email')->toString());
    }

    public function role(): Role
    {
        return Role::from($this->string('role')->toString());
    }
}
