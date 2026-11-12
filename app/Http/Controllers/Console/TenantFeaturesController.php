<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\ConsoleSession;
use App\Http\Requests\TenantFeaturesRequest;
use App\Platform\FeaturesRefused;
use App\Platform\TenantFeatures;
use App\Platform\TenantLookup;
use App\Tenancy\TenantUrls;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's Change features: POST /tenants/{slug}/features under operator, the body one list of
 * feature slugs, offered on the detail of a tenant in the free shape alone. It answers the redirect
 * back to the tenant's detail with a success flash, or the refusal's sentence as an error flash, a
 * toast that stays until dismissed; a body the form request refuses comes back on the field
 * instead. The slug is read as the detail reads it, and a slug naming no tenant answers the
 * console's 404 inside its shell, writing nothing.
 *
 * What the write does is App\Platform\TenantFeatures's: the row, the audit row, and the refusals.
 */
final readonly class TenantFeaturesController
{
    public const string SAVED = 'The features are saved.';

    public function __construct(
        private TenantFeatures $features,
        private TenantLookup $tenants,
        private ConsoleSession $session,
        private TenantUrls $urls,
        private NotFoundController $notFound,
    ) {}

    public function __invoke(TenantFeaturesRequest $request, string $slug): Response
    {
        $tenant = $this->tenants->bySlug($slug);

        if ($tenant === null) {
            return ($this->notFound)($request);
        }

        $operator = $this->session->operator($request) ?? throw new LogicException('A console write ran without an operator; its route declares no operator.');

        try {
            $this->features->change($tenant, $operator, $request->features());
            $flash = ['kind' => 'success', 'message' => self::SAVED];
        } catch (FeaturesRefused $refused) {
            $flash = ['kind' => 'error', 'message' => $refused->getMessage()];
        }

        $request->session()->flash('flash', $flash);

        return redirect()->to($this->urls->console('/tenants/'.$tenant->slug));
    }
}
