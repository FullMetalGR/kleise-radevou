<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\AdminProps;
use App\Http\AdminStrings;
use App\Http\ConsoleNav;
use App\Http\ConsoleSession;
use App\Platform\ConsoleTime;
use App\Site\Brand;
use App\Site\SiteLanguage;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * The Inertia adapter for the console's host: the central adapter's twin. The console's pages are
 * the admin bundle's, rendered from a root view of their own, and the props every page shares are
 * the flash, the operator, the console guard's operator's name or null, the validation errors
 * Inertia's base share hands every page, the rail as nav, ConsoleNav's for an operator and empty
 * for anyone else, and the console's zone, which the shell's top bar names once for every instant
 * on the screen and the date fields read their today in, and the brand, the public name the shell's
 * wordmark, the door and the title template read, and the strings, the admin's shell section read
 * through the translator with the locale fixed to English, since the console's door and shell
 * render the admin's kit, which spells no word of its own, and the console stays English; the
 * console's own pages keep their vocabulary. No tenant is resolved on this host, so nothing a
 * tenant page shares is handed here; the admin build's manifest is the asset version, as it is on
 * the other two hosts.
 */
final class HandleConsoleInertiaRequests extends Middleware
{
    public const string ROOT_VIEW = 'console.app';

    protected $rootView = self::ROOT_VIEW;

    public function __construct(private readonly ConsoleSession $session, private readonly ConsoleTime $time, private readonly Brand $brand, private readonly AdminStrings $strings) {}

    public function version(Request $request): ?string
    {
        return HandleInertiaRequests::manifestHash();
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $operator = $this->session->operator($request);

        return [
            ...parent::share($request),
            'flash' => AdminProps::flashOf($request->session()->get('flash')),
            'operator' => $operator?->name,
            // Read when the page renders, after the controller ran, so a 404 a routed page
            // answered through NotFoundController lights no entry.
            'nav' => $operator === null ? [] : fn (): array => ConsoleNav::for($request->attributes->get(ConsoleNav::NO_ENTRY) === true ? null : $request->route()?->getName()),
            'zone' => $this->time->zone()->getName(),
            'brand' => $this->brand->prop(),
            'strings' => fn (): array => self::stringsOf($this->strings),
        ];
    }

    /**
     * The strings the console renders through the admin's kit: the shell's section in English,
     * whatever a tenant's language. The door past its limit, answered before this adapter runs,
     * hands them itself from here.
     *
     * @return array{shell: array<mixed>}
     */
    public static function stringsOf(AdminStrings $strings): array
    {
        return $strings->shell(SiteLanguage::English);
    }
}
