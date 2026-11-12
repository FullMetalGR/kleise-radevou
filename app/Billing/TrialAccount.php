<?php

declare(strict_types=1);

namespace App\Billing;

use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A tenant the trial's clock has just moved, as the statement that moved it answers: who the
 * business is, the clock it keeps, when its trial ends or ended, the closing the platform wrote
 * where it closed the account, and the owner users to tell, each by address, name and the language
 * they chose, users.locale. The tenant's model rides beside them for the language each owner is
 * told in, their own else the tenant default, which the mail asks the resolver at send time.
 */
final readonly class TrialAccount
{
    /**
     * @param  list<array{address: string, name: string, locale: SiteLanguage|null}>  $owners
     */
    public function __construct(
        public int $id,
        public Slug $slug,
        public string $name,
        public DateTimeZone $zone,
        public DateTimeImmutable $trialEndsAt,
        public ?DateTimeImmutable $closingAt,
        public array $owners,
        public Tenant $tenant,
    ) {}
}
