<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Clock;
use App\Billing\Feature;
use App\Http\AdminLines;
use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\BookingLinks;
use App\Http\CalendarPage;
use App\Http\ErrorEnvelope;
use App\Http\FeatureGate;
use App\Http\LinkKind;
use App\Http\Requests\ImageUploadRequest;
use App\Http\Requests\StaffPhotoRequest;
use App\Http\Requests\StaffRequest;
use App\Infrastructure\Db\BlackoutRecords;
use App\Infrastructure\Db\ImplicitStaffKept;
use App\Infrastructure\Db\ServiceRecords;
use App\Infrastructure\Db\ShiftRecords;
use App\Infrastructure\Db\StaffRecords;
use App\Infrastructure\Db\StaffReferenced;
use App\Infrastructure\Storage\TenantImages;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Staff screen, and its writes under the prefix, in the shape ServiceController has: one page
 * answers the list, the create route and the detail route, each carrying every member whole, with
 * the catalog the services checklist ticks, the tenant's users the linked-user combobox picks from
 * (a prop, since the users of one tenant are few and the admin allows no second JSON endpoint),
 * every closure from the tenant's today on, soonest first, a run of consecutive dates folded into
 * one line naming its member or none (BlackoutRecords::upcoming; each member's own beside them the
 * same way), and every named member's booking link for the detail page's Copy booking link button,
 * BookingLinks; the implicit member has none, since it stands for the business. Each member carries
 * Hours this week beside them, the parts of the list's one sentence through the shift resolver, and
 * their own calendar colour, null for Automatic.
 *
 * Without the staff feature the create route and every write here answer not-found: a form whose
 * save the server would refuse is not rendered, and a write to a thing the tenant did not buy is
 * refused as a row nobody has. The refusal is asked before the form request resolves, so an invalid
 * body is not-found as well. The list and the detail read on, and no read is gated by the
 * subscription.
 *
 * A save is one write of the row and its links, so it produces one toast of its own; the per-member
 * links route is the service page's and flashes nothing. The delete guard's refusal is the one 409
 * the admin branches on, code referenced; the implicit member's is a second 409, code implicit,
 * shown as its sentence like any other worded answer, because the way forward there is not a remedy
 * but a fact: that row stays.
 *
 * Every word the screen shows is a key of the staff section, which every page here names beside the
 * shell's, the floor's closures included, and the flashes and the two refusals are keys of the same
 * file worded in the request's language. The photo's help names the largest file in words, which
 * ICU spells on the server alone, so the page carries the figure and its spelling beside the
 * section (photo_limit).
 */
final readonly class StaffController
{
    public const string PAGE = 'Staff';

    /** The screen's section of lang/{locale}/admin, beside the shell's. */
    public const string SECTION = 'staff';

    /** The writes' flashes, keys of staff.php worded in the request's language. */
    public const string CREATED = 'admin/staff.flashes.created';

    public const string SAVED = 'admin/staff.flashes.saved';

    public const string DEACTIVATED = 'admin/staff.flashes.deactivated';

    public const string REACTIVATED = 'admin/staff.flashes.reactivated';

    public const string DELETED = 'admin/staff.flashes.deleted';

    /**
     * The delete's two 409 refusals: a member some booking names, the implicit one.
     */
    public const string REFERENCED = 'admin/staff.refusals.referenced';

    public const string IMPLICIT = 'admin/staff.refusals.implicit';

    /** The photo help's key, read as a choice by the largest file's megabytes. */
    public const string PHOTO_HELP = 'admin/staff.form.photo_help';

    public function __construct(
        private CurrentTenant $current,
        private StaffRecords $staff,
        private ServiceRecords $services,
        private BlackoutRecords $blackouts,
        private FeatureGate $gate,
        private TenantUrls $urls,
        private BookingLinks $links,
        private ShiftRecords $shifts,
        private CalendarPage $calendar,
        private Clock $clock,
    ) {}

    public function index(Request $request): Response
    {
        return $this->page($request, ['kind' => 'list']);
    }

    public function create(Request $request): Response
    {
        $this->gate->require(Feature::Staff);

        return $this->page($request, ['kind' => 'create']);
    }

    public function show(Request $request, int $id): Response
    {
        return $this->page($request, ['kind' => 'detail', 'id' => $id]);
    }

    public function store(): RedirectResponse
    {
        $this->gate->require(Feature::Staff);
        $request = app(StaffRequest::class);
        $id = $this->staff->insert($this->current->get(), $request->patch(), $request->serviceIds() ?? []);
        $request->session()->flash('flash', ['kind' => 'success', 'message' => StaffRequest::line(self::CREATED)]);

        return redirect()->to(AdminSession::at($request, self::list().'/'.$id));
    }

    public function update(int $id): RedirectResponse
    {
        $this->gate->require(Feature::Staff);
        $request = app(StaffRequest::class);
        $patch = $request->patch();
        $serviceIds = $request->serviceIds();

        if (! $this->staff->update($this->current->get(), $id, $patch, $serviceIds)) {
            throw new NotFoundHttpException;
        }

        $remedy = $serviceIds === null && array_keys($patch) === ['status'];
        $request->session()->flash('flash', ['kind' => 'success', 'message' => StaffRequest::line($remedy ? ($patch['status'] === 'inactive' ? self::DEACTIVATED : self::REACTIVATED) : self::SAVED)]);

        return redirect()->back(fallback: AdminSession::at($request, self::list().'/'.$id));
    }

    public function destroy(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $this->gate->require(Feature::Staff);

        // The member's photo goes with them inside the delete, unless another member holds the
        // same file (StaffRecords::delete).
        try {
            if (! $this->staff->delete($this->current->get(), $id)) {
                throw new NotFoundHttpException;
            }
        } catch (ImplicitStaffKept) {
            return (new ErrorEnvelope(ImplicitStaffKept::CODE, 409, StaffRequest::line(self::IMPLICIT)))->toResponse();
        } catch (StaffReferenced) {
            return (new ErrorEnvelope(StaffReferenced::CODE, 409, StaffRequest::line(self::REFERENCED)))->toResponse();
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => StaffRequest::line(self::DELETED)]);

        return redirect()->to(AdminSession::at($request, self::list()));
    }

    /**
     * @param  array{kind: string, id?: int}  $view
     */
    private function page(Request $request, array $view): Response
    {
        $tenant = $this->current->get();
        $slug = Slug::of($tenant->slug);
        AdminStrings::need($request, self::SECTION);
        $megabytes = ImageUploadRequest::megabytesOf(StaffPhotoRequest::MAX_KILOBYTES);

        return Inertia::render(self::PAGE, [
            'view' => $view,
            'staff' => fn (): array => $this->withHours($tenant, $this->withClosures($tenant, array_map(fn (array $member): array => $this->withPhoto($slug, $member), $this->staff->all($tenant)))),
            'services' => fn (): array => $this->services->all($tenant),
            'users' => fn (): array => User::query()->where('tenant_id', $tenant->id)->orderBy('name')->orderBy('id')->get(['id', 'name', 'email'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->all(),
            'closures' => fn (): array => $this->blackouts->upcoming($tenant, $this->today($tenant)),
            'links' => fn (): array => $this->links->of($tenant, LinkKind::Staff),
            'photo_limit' => fn (): array => ['megabytes' => $megabytes, 'spelled' => AdminLines::spelled(self::PHOTO_HELP, $megabytes)],
        ]);
    }

    /**
     * A member as the screen reads them: the stored photo's path swapped for what the photo
     * control shows, the file's name and the URL the page loads it from, or null where none.
     *
     * @param  array{photo_path: string|null}  $member
     * @return array<string, mixed>
     */
    private function withPhoto(Slug $slug, array $member): array
    {
        $path = $member['photo_path'];
        unset($member['photo_path']);
        $file = $path === null ? null : TenantImages::fileOf($path);

        return [...$member, 'photo' => $file === null ? null : ['name' => $file, 'url' => $this->urls->image($slug, $file)]];
    }

    /**
     * The members with their own closures as the page lists them: today onward, soonest first, a
     * run folded into one line, as the screen's closures card lists everybody's.
     *
     * @param  list<array<string, mixed>>  $members
     * @return list<array<string, mixed>>
     */
    private function withClosures(Tenant $tenant, array $members): array
    {
        $own = [];

        foreach ($this->blackouts->upcoming($tenant, $this->today($tenant)) as $line) {
            if ($line['staff_id'] !== null) {
                $own[$line['staff_id']][] = ['date' => $line['date'], 'until' => $line['until'], 'start' => $line['start'], 'end' => $line['end'], 'reason' => $line['reason']];
            }
        }

        return array_map(fn (array $member): array => [...$member, 'blackouts' => $own[BlackoutRecords::int($member['id'] ?? null)] ?? []], $members);
    }

    /** The tenant's today, a machine date on its own clock. */
    private function today(Tenant $tenant): string
    {
        return $this->clock->now()->setTimezone($tenant->zone())->format('Y-m-d');
    }

    /**
     * The members with Hours this week beside each: the current week's minutes in the tenant's
     * week start through the shift resolver, closures subtracted, the parts of the list's one
     * sentence (ShiftRecords::hoursOf).
     *
     * @param  list<array<string, mixed>>  $members
     * @return list<array<string, mixed>>
     */
    private function withHours(Tenant $tenant, array $members): array
    {
        $ids = array_map(fn (array $member): int => BlackoutRecords::int($member['id'] ?? null), $members);
        $hours = $this->shifts->hoursOf($tenant, $ids, $this->calendar->thisWeek($tenant));

        return array_map(fn (array $member): array => [...$member, 'hours' => $hours[BlackoutRecords::int($member['id'] ?? null)] ?? ['minutes' => 0, 'weekly' => false, 'closures' => []]], $members);
    }

    public static function list(): string
    {
        return AdminSession::PREFIX.'/staff';
    }
}
