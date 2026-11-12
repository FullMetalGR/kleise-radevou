<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminLines;
use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\BookingLinks;
use App\Http\ErrorEnvelope;
use App\Http\LinkKind;
use App\Http\Requests\ServiceRequest;
use App\Infrastructure\Db\ServiceRecords;
use App\Infrastructure\Db\ServiceReferenced;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Services screen, and its writes under the prefix.
 *
 * One page answers the catalog, the create route and the detail route: every one of them carries
 * the whole catalog, because the list is always fetched whole and the detail page resolves its
 * row from it on the client, notFound being the list settled without that id. The view prop says
 * which of the three the request was, and the roster is what the staff checklist ticks, every
 * member with the services they perform and their windows. The links are every service's booking
 * link, which the detail page's Copy booking link button copies, BookingLinks.
 *
 * The writes answer as the wire does: a success is an Inertia redirect with a flash, a refusal is
 * the envelope. A validation refusal is the field's own sentence at 400 through the form request,
 * the subscription gate's is the middleware's 403, and the delete guard's is the one 409 the
 * admin branches on, code referenced. Nothing here is gated by the subscription on a read.
 *
 * Every word the screen shows is a key of the services section, which every page here names
 * beside the shell's, and the flashes and the referenced refusal are keys of the same file worded
 * in the request's language.
 */
final readonly class ServiceController
{
    public const string PAGE = 'Services';

    /** The screen's section of lang/{locale}/admin, beside the shell's. */
    public const string SECTION = 'services';

    /** The writes' flashes, keys of services.php worded in the request's language. */
    public const string CREATED = 'admin/services.flashes.created';

    public const string SAVED = 'admin/services.flashes.saved';

    public const string DEACTIVATED = 'admin/services.flashes.deactivated';

    public const string REACTIVATED = 'admin/services.flashes.reactivated';

    public const string DELETED = 'admin/services.flashes.deleted';

    /** The delete guard's refusal, ServiceReferenced's 409 detail. */
    public const string REFERENCED = 'admin/services.refusals.referenced';

    public function __construct(
        private CurrentTenant $current,
        private ServiceRecords $services,
        private BookingLinks $links,
    ) {}

    public function index(Request $request): Response
    {
        return $this->page($request, ['kind' => 'list']);
    }

    public function create(Request $request): Response
    {
        return $this->page($request, ['kind' => 'create']);
    }

    public function show(Request $request, int $id): Response
    {
        return $this->page($request, ['kind' => 'detail', 'id' => $id]);
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        $id = $this->services->insert($this->current->get(), $request->patch());
        $request->session()->flash('flash', ['kind' => 'success', 'message' => AdminLines::line(self::CREATED)]);

        return redirect()->to(AdminSession::at($request, self::list().'/'.$id));
    }

    public function update(ServiceRequest $request, int $id): RedirectResponse
    {
        $patch = $request->patch();

        if (! $this->services->update($this->current->get(), $id, $patch)) {
            throw new NotFoundHttpException;
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => AdminLines::line(self::wordFor($patch))]);

        return redirect()->back(fallback: AdminSession::at($request, self::list().'/'.$id));
    }

    public function destroy(Request $request, int $id): RedirectResponse|JsonResponse
    {
        try {
            if (! $this->services->delete($this->current->get(), $id)) {
                throw new NotFoundHttpException;
            }
        } catch (ServiceReferenced) {
            return (new ErrorEnvelope(ServiceReferenced::CODE, 409, AdminLines::line(self::REFERENCED)))->toResponse();
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => AdminLines::line(self::DELETED)]);

        return redirect()->to(AdminSession::at($request, self::list()));
    }

    /**
     * @param  array{kind: string, id?: int}  $view
     */
    private function page(Request $request, array $view): Response
    {
        $tenant = $this->current->get();
        AdminStrings::need($request, self::SECTION);

        return Inertia::render(self::PAGE, [
            'view' => $view,
            'services' => fn (): array => $this->services->all($tenant),
            'categories' => fn (): array => $this->services->categories($tenant),
            'roster' => fn (): array => $this->services->roster($tenant),
            'links' => fn (): array => $this->links->of($tenant, LinkKind::Service),
        ]);
    }

    /**
     * What a save is called: a patch of the status alone is a deactivation or its reverse, and
     * says so; anything else is a save.
     *
     * @param  array<string, mixed>  $patch
     */
    private static function wordFor(array $patch): string
    {
        if (array_keys($patch) !== ['status']) {
            return self::SAVED;
        }

        return $patch['status'] === 'inactive' ? self::DEACTIVATED : self::REACTIVATED;
    }

    private static function list(): string
    {
        return AdminSession::PREFIX.'/services';
    }
}
