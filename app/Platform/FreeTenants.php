<?php

declare(strict_types=1);

namespace App\Platform;

use App\Models\User;
use App\Notifications\SetPasswordLink;
use App\Tenancy\InvalidSlug;
use App\Tenancy\ProvisioningRefused;
use App\Tenancy\Role;
use App\Tenancy\Signup;
use App\Tenancy\SlugProblem;
use App\Tenancy\Tenant;
use App\Tenancy\TenantProvisioner;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The free tenant: the one exception to the no-trial rule, granted by the operator alone. The
 * tenant is built through the provisioner's console path exactly as tenant:provision builds one, so
 * a free tenant and a development tenant are one shape: active once its schema is built, the
 * subscription state the table's active, the chosen set on the row, no subscriptions row and no
 * trial end (TenantPlan's free).
 *
 * The owner is written with a random secret as the password: 32 bytes from random_bytes as 64 hex
 * characters, handed to the model's hashed cast and dropped, so nobody is shown it, nothing logs it
 * and nobody can sign in with it. Once the build has committed, one transaction mints the owner's
 * set-password link (PasswordLinks::mint) and records tenant_created with the features as their
 * slugs; once that has committed the link is mailed to the owner through the platform's seam,
 * worded for a first password. The owner gets in through the link and no other way.
 * PasswordLinks::send is not used, since it records password_link_sent and the creation records
 * tenant_created alone. The language the form chose is written to the row as tenants.locale, and
 * the link's mail follows it; no welcome email goes out, since this path builds no trial.
 *
 * The form judges the slug before this runs, through SlugRules, as the signup does. The build still
 * meets a row of that slug where one appeared between the check and the build, and refuses it:
 * suspended, closing or held by a signup as the provisioner refuses it, and any row this create did
 * not make as taken: an active one, which the console path hands back unchanged for a retried
 * tenant:provision, and a provisioning one with no owner yet, another create's build in flight,
 * which the build is told to leave unbuilt rather than finish, so no second owner, no second
 * tenant_created and no 500 on the schema's name follow. A row another run inserts between the
 * rules' check and this create's insert meets the unique index, and the provisioner answers it as
 * the slug taken. Each refusal is the slug field's sentence, with nothing built, recorded or sent.
 *
 * A failure after the build leaves the tenant standing with no link and no audit row, as a failure
 * between tenant:provision's two transactions leaves its row: the detail's set-password control
 * sends the owner a link.
 */
final readonly class FreeTenants
{
    /** How many random bytes the owner's unusable password is made of. */
    public const int SECRET_BYTES = 32;

    public function __construct(
        private TenantProvisioner $provisioner,
        private PasswordLinks $links,
        private SetPasswordLink $mail,
        private DatabaseManager $db,
    ) {}

    /**
     * Builds the tenant, records its creation, and mails its owner the set-password link.
     *
     * @throws FreeTenantRefused where the slug's row is one the build must not touch or already exists
     */
    public function create(NewFreeTenant $details, Operator $operator): Tenant
    {
        $signup = new Signup(
            slug: $details->slug,
            name: $details->name,
            timezone: $details->timezone,
            currency: $details->currency,
            ownerName: $details->ownerName,
            ownerEmail: $details->ownerEmail,
            ownerPassword: bin2hex(random_bytes(self::SECRET_BYTES)),
            entitlements: $details->features,
            category: $details->category,
            locale: $details->locale,
        );

        try {
            $provisioned = $this->provisioner->provision($signup, finishFound: false);
        } catch (ProvisioningRefused $refused) {
            throw FreeTenantRefused::because($refused->getMessage());
        } catch (InvalidSlug $refused) {
            throw FreeTenantRefused::because($refused->problem->detail());
        }

        if (! $provisioned->created) {
            throw FreeTenantRefused::because(SlugProblem::Taken->detail());
        }

        $tenant = $provisioned->tenant;

        ['owner' => $owner, 'token' => $token] = $this->connection()->transaction(function (Connection $db) use ($tenant, $details, $operator): array {
            $owner = User::query()->where('tenant_id', $tenant->id)->where('email', $details->ownerEmail)->where('role', Role::Owner->value)->sole();
            $token = $this->links->mint($owner);
            PlatformAudit::record($db, $operator->id, new PlatformAuditEntry(PlatformAuditAction::TenantCreated, [PlatformAuditKey::Features->value => $details->features->toSlugs()]), $tenant->id);

            return ['owner' => $owner, 'token' => $token];
        });

        $this->mail->send($tenant, $owner, $token, firstPassword: true);

        return $tenant;
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
