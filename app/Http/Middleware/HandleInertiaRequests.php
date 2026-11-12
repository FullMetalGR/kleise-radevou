<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\AdminProps;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * The admin's Inertia middleware: the root view of its own, the asset version read from the admin
 * bundle's manifest, and the shared props from AdminProps. It runs on the admin prefix and
 * nowhere else; the booking app is JSON over a public API and Inertia never serves it.
 */
final class HandleInertiaRequests extends Middleware
{
    public const string ROOT_VIEW = 'admin.app';

    /**
     * Where the admin build writes its manifest, relative to public/.
     */
    public const string BUILD_DIRECTORY = 'build/admin';

    protected $rootView = self::ROOT_VIEW;

    public function __construct(private readonly AdminProps $props) {}

    public function version(Request $request): ?string
    {
        return self::manifestHash();
    }

    /**
     * The asset version of every page the admin bundle renders, on all three hosts: the hash of
     * the admin build's manifest, or null where no build has written one. The one reading of it,
     * which the central and the console adapters version their pages by as this one does, so a
     * deploy that rebuilds the bundle reloads every open page alike.
     */
    public static function manifestHash(): ?string
    {
        $manifest = public_path(self::BUILD_DIRECTORY.'/manifest.json');

        return is_file($manifest) ? hash_file('xxh128', $manifest) ?: null : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [...parent::share($request), ...$this->props->for($request)];
    }
}
