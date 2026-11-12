<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\AdminProps;
use App\Http\AdminStrings;
use App\Http\SignupStrings;
use App\Site\Brand;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * The Inertia adapter for the central host: signup's pages are the admin bundle's, rendered from
 * their own root view, the signup's frame, and the props every page shares are the flash, the
 * brand, the public name the title template reads, and the request's language with the signup's
 * strings in it, the sections of signup.php the pages render as one prop, since the pages hold no
 * sentence of their own, and beside them under shell, a name signup.php does not hold, the groups
 * of the admin's shell section the pages render through the admin's kit, the toaster and the
 * controls, read in the same language. No tenant is resolved on this host, so nothing a tenant
 * page shares is handed here; the admin build's manifest is the asset version, as it is on a
 * tenant host.
 */
final class HandleCentralInertiaRequests extends Middleware
{
    public const string ROOT_VIEW = 'central.app';

    protected $rootView = self::ROOT_VIEW;

    public function __construct(private readonly Brand $brand, private readonly SignupStrings $strings, private readonly AdminStrings $kit) {}

    public function version(Request $request): ?string
    {
        return HandleInertiaRequests::manifestHash();
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'flash' => AdminProps::flashOf($request->session()->get('flash')),
            ...$this->hostProps(),
        ];
    }

    /**
     * The host's props beside the flash and the framework's errors: the brand, the request's
     * language and the signup's strings in it, the kit's words among them. share() hands them to
     * every page this adapter sees; the signup limiter answers before it has run, so that refusal
     * hands them itself from here, and reads as every other page does.
     *
     * @return array{brand: array{name: string}, language: string, strings: array<mixed>}
     */
    public function hostProps(): array
    {
        $signup = $this->strings->props();

        return ['brand' => $this->brand->prop(), 'language' => $signup['language'], 'strings' => [...$signup['strings'], ...$this->kit->kit()]];
    }
}
