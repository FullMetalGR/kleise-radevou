<?php

use App\Domain\Lifetime;
use App\Platform\ClosingWindow;
use App\Platform\PasswordLinks;
use App\Tenancy\SignupVerification;
use App\Tenancy\TenantProvisioner;

/*
 * The one reading of an interval as a length the mails and the screens count by: whole hours and
 * whole days, each counted in seconds from the epoch in UTC rather than by one field of the
 * interval, any part of an hour or a day left over dropped.
 */

it('counts an interval in whole hours whatever fields it is spelled in', function () {
    expect(Lifetime::hours('PT72H'))->toBe(72)
        ->and(Lifetime::hours('P3D'))->toBe(72)
        ->and(Lifetime::hours('P1DT2H'))->toBe(26)
        ->and(Lifetime::hours('PT4320M'))->toBe(72)
        ->and(Lifetime::hours('PT90M'))->toBe(1)
        ->and(Lifetime::hours('P1M'))->toBe(31 * 24);
});

it('counts an interval in whole days whatever fields it is spelled in', function () {
    expect(Lifetime::days('P7D'))->toBe(7)
        ->and(Lifetime::days('P1W'))->toBe(7)
        ->and(Lifetime::days('P30D'))->toBe(30)
        ->and(Lifetime::days('PT23H'))->toBe(0)
        ->and(Lifetime::days('PT25H'))->toBe(1)
        ->and(Lifetime::days('PT720H'))->toBe(30)
        ->and(Lifetime::days('P1M'))->toBe(31);
});

it('holds the set-password link, the verification link, the trial and the closing window to their lengths', function () {
    expect(PasswordLinks::hours())->toBe(Lifetime::hours(PasswordLinks::LIFETIME))
        ->and(PasswordLinks::hours())->toBe(72)
        ->and(Lifetime::hours(SignupVerification::LINK_LIFETIME))->toBe(24)
        ->and(TenantProvisioner::trialDays())->toBe(7)
        ->and(ClosingWindow::days())->toBe(30);
});
