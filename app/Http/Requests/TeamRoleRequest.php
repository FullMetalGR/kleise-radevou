<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Tenancy\Role;

/**
 * The body of PUT /admin/team/{id}: one of the three roles, written at once from the member's
 * row. The refusal is a key of admin/team.php's validation, read in the request's language.
 */
final class TeamRoleRequest extends AdminWriteRequest
{
    /** The refusal of a role that is none of the three. */
    public const string REFUSED = 'admin/team.validation.role';

    /** The role's rules, the invitation's too. */
    public const array ROLE = ['required', 'string', 'in:owner,manager,staff'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['role' => self::ROLE];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['role.*' => StaffRequest::line(self::REFUSED)];
    }

    public function role(): Role
    {
        return Role::from($this->string('role')->toString());
    }
}
