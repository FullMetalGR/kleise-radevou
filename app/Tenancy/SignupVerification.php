<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use App\Models\User;
use App\Site\SiteLanguage;
use DateInterval;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use UnexpectedValueException;

/**
 * The signup's proof of address: the reservation mints the owner's link, the click builds the
 * trial, and the resend mints a new link while the row still waits.
 *
 * The link is 32 random bytes as hex. Its sha256 sits on the owner's users row, and the clear
 * token lives in the link alone: nothing here logs it, stores it or hands it to anything but the
 * caller that puts it in the message. The hash stays after the build, the owner's
 * email_verified_at marking it used: the 24 hours the slug is held, measured from the row's
 * created_at against the application's clock, bound the build alone, and a used link opens the
 * sign-in page whenever it is clicked, since people click twice and mail scanners fetch links
 * first. A resend replaces the hash only while the owner is unverified.
 */
final readonly class SignupVerification
{
    /** How long a link works, from the reservation: the hold's own 24 hours. */
    public const string LINK_LIFETIME = TenantProvisioner::ABANDONED_AFTER;

    public function __construct(
        private TenantProvisioner $provisioner,
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    /**
     * Reserves the slug with the owner under it and the hash of a new link beside the owner, in
     * one commit; answers the row and the clear token for the message.
     *
     * @return array{tenant: Tenant, token: string}
     *
     * @throws InvalidSlug when the slug is malformed, reserved or held
     */
    public function reserve(Signup $signup): array
    {
        $token = self::mint();

        return ['tenant' => $this->provisioner->reserve($signup, self::hashOf($token)), 'token' => $token];
    }

    /**
     * The click, by the owner its hash names. An owner already verified sends to the sign-in page,
     * the row built by this link before, whenever the click comes. An unverified owner's row
     * reserved less than 24 hours ago is built as a trial under its lock, the owner's
     * email_verified_at stamped in the same commit and the hash kept; a racing click that took the
     * lock first leaves it built, and this one answers as found, not built. The click that built
     * answers the trial as the build left it, re-read after the commit, for the welcome email its
     * door sends. Answers null for a link that has expired: one no owner holds (unknown, tampered,
     * replaced by a resend, or swept with its row), an unverified owner's row reserved more than
     * 24 hours ago, or a row the lock finds in any state but provisioning or active.
     */
    public function verify(string $token): ?VerifiedSignup
    {
        $now = $this->clock->now();
        $row = (array) $this->db->connection()->selectOne(
            'select u.id as user_id, u.email_verified_at is not null as verified, t.created_at > ?::timestamptz as fresh, t.id as tenant_id, t.slug, t.locale
             from users u join tenants t on t.id = u.tenant_id where u.verification_token_hash = ?',
            [SqlText::instant($now->sub(new DateInterval(self::LINK_LIFETIME))), self::hashOf($token)],
        );

        if ($row === []) {
            return null;
        }

        [$userId, $tenantId, $slug, $locale] = [$row['user_id'] ?? null, $row['tenant_id'] ?? null, $row['slug'] ?? null, $row['locale'] ?? null];

        if (! is_int($userId) || ! is_int($tenantId) || ! is_string($slug) || ! is_string($locale)) {
            throw new UnexpectedValueException('The verification lookup read a row whose ids, slug or locale are not of the types the schema declares.');
        }

        $language = SiteLanguage::from($locale);

        if (($row['verified'] ?? null) === true) {
            return new VerifiedSignup(Slug::of($slug), $language, built: false);
        }

        if (($row['fresh'] ?? null) !== true) {
            return null;
        }

        $found = $this->provisioner->provisionClaimedTrial($tenantId, function (Connection $db) use ($userId, $now): void {
            $db->table('users')->where('id', $userId)->update(['email_verified_at' => SqlText::instant($now)]);
        });

        return match ($found) {
            TenantStatus::Provisioning => new VerifiedSignup(Slug::of($slug), $language, built: true, trial: $this->built($tenantId, $userId)),
            TenantStatus::Active => new VerifiedSignup(Slug::of($slug), $language, built: false),
            default => null,
        };
    }

    /**
     * The trial as the click's build left it, the row and its owner re-read once the build has
     * committed, for the welcome email.
     */
    private function built(int $tenantId, int $userId): BuiltTrial
    {
        $tenant = Tenant::query()->find($tenantId);
        $owner = User::query()->find($userId);

        if (! $tenant instanceof Tenant || ! $owner instanceof User) {
            throw new UnexpectedValueException('The click built a trial whose row or owner cannot be read back.');
        }

        return new BuiltTrial($tenant, $owner);
    }

    /**
     * The pending page's resend: a new link for the slug's owner while the row is provisioning,
     * the owner unverified and the reservation inside its 24 hours, the new hash replacing the old
     * in one statement, so the old link names no owner from then on. A click building the row at
     * the same moment orders against it on the owner's row: whichever writes second sees the
     * other's write, and a resend after the stamp replaces nothing. Answers the row, the owner's
     * address and name and the clear token for the message, or null where nothing is to be sent: a
     * row built, unknown, swept, or past its 24 hours. The slug arrives judged by the route's
     * controller.
     *
     * @return array{tenant: Tenant, email: string, name: string, token: string}|null
     */
    public function resend(Slug $label): ?array
    {
        $token = self::mint();
        $row = (array) $this->db->connection()->selectOne(
            'update users u set verification_token_hash = ? from tenants t
             where t.id = u.tenant_id and t.slug = ? and t.status = ? and u.role = ? and u.email_verified_at is null and t.created_at > ?::timestamptz
             returning t.id as tenant_id, u.email, u.name',
            [self::hashOf($token), $label->value, TenantStatus::Provisioning->value, Role::Owner->value, SqlText::instant($this->clock->now()->sub(new DateInterval(self::LINK_LIFETIME)))],
        );

        if ($row === []) {
            return null;
        }

        [$tenantId, $email, $name] = [$row['tenant_id'] ?? null, $row['email'] ?? null, $row['name'] ?? null];
        $tenant = is_int($tenantId) ? Tenant::query()->find($tenantId) : null;

        if (! $tenant instanceof Tenant || ! is_string($email) || ! is_string($name)) {
            throw new UnexpectedValueException('The resend replaced the link of an owner whose row, address or name is not of the types the schema declares.');
        }

        return ['tenant' => $tenant, 'email' => $email, 'name' => $name, 'token' => $token];
    }

    private static function mint(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }
}
