<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use LogicException;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;

/**
 * The admin as it is looked at: the screens the menu reaches, the themes it is read in, and the
 * screenshots a run saves to be read by eye. A screenshot is saved only when LOOK_SHOTS names a
 * run, under tests/Browser/Screenshots/<run>, which is never committed; without it a walk
 * asserts and saves nothing, which is how the gates run it.
 */
final class Look
{
    /**
     * Every screen of the admin the rail reaches, by name, with its path and what shows it is
     * drawn: Shifts by the edit control of its first member's first day and Team by a member's
     * row, since each of those tables holds a line only a screen reader hears, placed out of the
     * flow, and is read with it on the page.
     *
     * @var array<string, array{path: string, hook: string}>
     */
    public const array SCREENS = [
        'home' => ['path' => '', 'hook' => '.admin-home-screen'],
        'calendar' => ['path' => '/calendar', 'hook' => '.admin-calendar-screen'],
        'bookings' => ['path' => '/bookings', 'hook' => '.admin-bookings-screen'],
        'customers' => ['path' => '/customers', 'hook' => '.admin-customers-screen'],
        'services' => ['path' => '/services', 'hook' => '.admin-services-screen'],
        'staff' => ['path' => '/staff', 'hook' => '.admin-staff-screen'],
        'shifts' => ['path' => '/shifts', 'hook' => '.admin-shifts__row:first-child .admin-shifts__cell:first-of-type .admin-shifts__edit'],
        'team' => ['path' => '/team', 'hook' => '.admin-team-member'],
        'reports' => ['path' => '/reports', 'hook' => '.admin-reports-screen'],
        'settings' => ['path' => '/settings', 'hook' => '.admin-settings-page'],
        'billing' => ['path' => '/billing', 'hook' => '.admin-billing'],
    ];

    /**
     * The themes a screenshot run reads by default: Neutral, whose tints are greys, Blue, and
     * Rose, whose primary has the least room on white.
     *
     * @var list<string>
     */
    public const array THEMES = ['neutral', 'blue', 'rose'];

    /**
     * The seven themes a tenant can pick, in the order of the settings column's list.
     *
     * @var list<string>
     */
    public const array ALL_THEMES = ['neutral', 'blue', 'green', 'orange', 'red', 'rose', 'violet'];

    /**
     * A script that dresses the open page in a theme and a mode, as the root view and the theme
     * switch would: the theme as the body's data-theme, the dark mode as the root's class. It
     * answers the theme the body then carries. The next page loaded is dressed as the server and
     * the browser's remembered mode say again.
     */
    public static function wear(string $theme, bool $dark): string
    {
        return sprintf(
            "(() => { document.body.dataset.theme = %s; document.documentElement.classList.toggle('dark', %s); return document.body.dataset.theme; })()",
            json_encode($theme, JSON_THROW_ON_ERROR),
            $dark ? 'true' : 'false',
        );
    }

    /**
     * Saves a full-page screenshot of the page as tests/Browser/Screenshots/<run>/<name>.png
     * when LOOK_SHOTS names the run, and does nothing otherwise.
     */
    public static function shot(AwaitableWebpage|Webpage $page, string $name): void
    {
        $run = self::run();

        if ($run === null) {
            return;
        }

        $folder = base_path('tests/Browser/Screenshots/'.$run);

        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        $page->screenshot(true, $run.'/'.$name);
    }

    /**
     * The widths a walk reads every screen at: a tablet's and a desktop's, and a phone's first
     * when a run saves screenshots, since the phone walk holds its own assertions at that width.
     *
     * @return list<int>
     */
    public static function widths(): array
    {
        return self::shooting() ? [390, 800, 1440] : [800, 1440];
    }

    /** Whether LOOK_SHOTS names a run, so a walk dresses and saves each stop. */
    public static function shooting(): bool
    {
        return self::run() !== null;
    }

    /**
     * The screens a walk reads: every one, or those LOOK_SCREENS names, a comma list of the
     * names SCREENS gives. A name it does not give stops the walk, which would otherwise pass
     * having read nothing.
     *
     * @return array<string, array{path: string, hook: string}>
     */
    public static function screens(): array
    {
        $names = getenv('LOOK_SCREENS');

        if (! is_string($names) || trim($names) === '') {
            return self::SCREENS;
        }

        $named = array_map(trim(...), explode(',', $names));
        $unknown = array_diff($named, array_keys(self::SCREENS));

        if ($unknown !== []) {
            throw new LogicException(sprintf('LOOK_SCREENS names no screen called %s.', implode(', ', $unknown)));
        }

        return array_intersect_key(self::SCREENS, array_flip($named));
    }

    /**
     * The themes a screenshot run reads: THEMES, or all seven with LOOK_THEMES=all.
     *
     * @return list<string>
     */
    public static function themes(): array
    {
        return getenv('LOOK_THEMES') === 'all' ? self::ALL_THEMES : self::THEMES;
    }

    /**
     * The run LOOK_SHOTS names, or null when none is named. The name becomes a folder under the
     * screenshots', so it is letters, digits, "_" and "-" alone; any other name stops the walk
     * before a path is built from it.
     */
    private static function run(): ?string
    {
        $run = getenv('LOOK_SHOTS');

        if (! is_string($run) || $run === '') {
            return null;
        }

        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $run) !== 1) {
            throw new LogicException('LOOK_SHOTS names a run in letters, digits, "_" and "-" alone.');
        }

        return $run;
    }
}
