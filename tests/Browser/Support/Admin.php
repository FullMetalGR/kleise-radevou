<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use App\Http\AdminSession;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Pest\Browser\Api\On;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Tests\Browser\BrowserTestCase;

/**
 * The admin as a browser reaches it: a URL under a tenant's admin prefix, and signing in through
 * the real form, which is where every admin browser test starts. The demo seeder's owners sign in
 * with the password "password" at owner@{slug}.example.
 *
 * Here rather than in a Pest file for the reason Flow is: a file's functions are global, and two
 * files declaring one helper is a fatal error the moment both load.
 */
final class Admin
{
    public const string DEMO_PASSWORD = 'password';

    /**
     * The rail's one entry for the Staff screen, reached by its target whatever its label, Staff
     * with the feature or Closures without it, since a page's text holds both words elsewhere.
     */
    public const string STAFF_ENTRY = '.admin-rail a[href="/admin/staff"]';

    public static function url(string $slug, string $path = ''): string
    {
        return app(TenantUrls::class)->origin(Slug::of($slug)).AdminSession::PREFIX.$path;
    }

    public static function owner(string $slug): string
    {
        return sprintf('owner@%s.example', $slug);
    }

    /**
     * Opens the sign-in page at a tenant and submits the form, and answers the page the browser
     * is left on. Waiting for what should follow is the caller's, since a refusal is a journey
     * too. The page comes back as the plugin's awaitable wrapper, which stands in for the webpage
     * it wraps, so the type is said in the docblock alone.
     *
     * @return Webpage
     */
    public static function signIn(BrowserTestCase $test, string $slug, string $email, string $password = self::DEMO_PASSWORD)
    {
        return self::submit($test->visit(self::url($slug, '/login')), $email, $password);
    }

    /** The shell's rail, which every signed-in admin page carries at the desktop's width. */
    public const string RAIL = '.admin-rail';

    /**
     * Signs in as signIn does and waits for the shell's rail, the sign-in having landed on a
     * page inside the shell: the one wait an owner's walk opens with, whatever entry the rail
     * lands on.
     *
     * @return Webpage
     */
    public static function signedIn(BrowserTestCase $test, string $slug, string $email, string $password = self::DEMO_PASSWORD)
    {
        return self::signIn($test, $slug, $email, $password)->assertVisible(self::RAIL);
    }

    /** The Calendar's path under the prefix, since Home took /admin. */
    public const string CALENDAR = '/calendar';

    /**
     * Signs in as signedIn does, landing on Home for a viewBookings holder, then opens the Calendar
     * by its URL on Week by its link: the walk every calendar case written for Week opens with. The
     * bare address opens on Day, which the Home walk's reload proves.
     *
     * @return Webpage
     */
    public static function onCalendar(BrowserTestCase $test, string $slug, string $email, string $password = self::DEMO_PASSWORD)
    {
        return self::signedIn($test, $slug, $email, $password)->navigate(self::url($slug, self::CALENDAR.'?view=week'));
    }

    /**
     * Signs in as signIn does, on the plugin's iPhone 15, 390 by 844: the admin below its
     * breakpoint. The device belongs to the visit, so the whole walk stays on it.
     *
     * @return Webpage
     */
    public static function signInOnPhone(BrowserTestCase $test, string $slug, string $email, string $password = self::DEMO_PASSWORD)
    {
        return self::submit($test->visit(self::url($slug, '/login'))->on()->iPhone15(), $email, $password);
    }

    /** Home's screen, where a viewBookings holder's sign-in lands. */
    public const string HOME = '.admin-home-screen';

    /**
     * Signs in on the phone as signInOnPhone does, waits for Home, then opens the Calendar by its
     * URL.
     *
     * @return Webpage
     */
    public static function onPhoneCalendar(BrowserTestCase $test, string $slug, string $email, string $password = self::DEMO_PASSWORD)
    {
        return self::signInOnPhone($test, $slug, $email, $password)->assertVisible(self::HOME)->navigate(self::url($slug, self::CALENDAR));
    }

    /**
     * Fills and submits the form on the visit given: a pending page, or the device's wrapper
     * around one, which hands every call on to the page it opens.
     *
     * @return Webpage
     */
    private static function submit(PendingAwaitablePage|On $visit, string $email, string $password)
    {
        return $visit->fill('email', $email)
            ->fill('password', $password)
            ->click('.admin-login-submit');
    }

    /**
     * A script answering whether the element's text is the line given with its :hours filled by
     * the browser's own ICU figure of whole hours in English, as lib/names.ts spells it, so a
     * walk pins no unit by hand.
     *
     * @param  array<string, string>  $values  the line's other placeholders, filled on this side
     */
    public static function hoursLineIs(string $selector, string $key, int $hours, array $values = []): string
    {
        return sprintf(
            "document.querySelector(%s)?.textContent === %s.replace(':hours', new Intl.NumberFormat('en', { style: 'unit', unit: 'hour', unitDisplay: 'narrow' }).format(%d))",
            json_encode($selector, JSON_THROW_ON_ERROR),
            json_encode(adminLine($key, $values), JSON_THROW_ON_ERROR),
            $hours,
        );
    }
}
