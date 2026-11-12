<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Clock;
use App\Application\Dto\BookingFilter;
use App\Application\Dto\BookingRecord;
use App\Application\Dto\CustomerNote;
use App\Application\Dto\CustomerProfile;
use App\Application\Dto\CustomerRecord;
use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\BookingPayload;
use App\Http\CalendarPage;
use App\Http\CsvDownload;
use App\Http\CustomerCsv;
use App\Http\CustomerPayload;
use App\Http\ErrorEnvelope;
use App\Http\Principal;
use App\Http\Requests\CustomerProfileRequest;
use App\Http\Requests\CustomerRequest;
use App\Infrastructure\Db\BookingRecords;
use App\Infrastructure\Db\Busy;
use App\Infrastructure\Db\CalendarRoster;
use App\Infrastructure\Db\CustomerNotes;
use App\Infrastructure\Db\CustomerProfiles;
use App\Infrastructure\Db\CustomerRecords;
use App\Infrastructure\Db\SettingsReader;
use App\Retention\CustomerErasure;
use App\Retention\CustomerExport;
use App\Retention\CustomerLive;
use App\Retention\CustomerUnknown;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use NumberFormatter;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Customers screen and a customer's page, both under manageBookings, the permission the CONTACT
 * rule reads, since a page is linked by the address it lists, built from what the bookings already
 * know; and the erasure and the export of the customer from their page, under the same permission.
 *
 * The list is every address the bookings know, CustomerRecords, each through CustomerPayload with
 * the viewer's permissions, searched over the columns BookingPayload::searchable gives this
 * viewer. The query is the request's own, read back eager because it costs nothing, and the page
 * of customers a closure, so a search or a page is a partial reload of it alone. Nothing is
 * remembered but what the URL carries.
 *
 * Each row of the list and the page's customer carry the address's tags and whether it is blocked,
 * read from customer_profiles for the page's addresses in one statement; the page carries the
 * profile its Profile card edits, the tags, the birthday and the language, which
 * CustomerProfileController writes, with the earliest birthday it takes, where Birthday's calendar
 * starts, and the Spent line's sums, what the address's visits came to in each currency; the block
 * on the address's online booking, the instant it was made and the staff's reason, both null where
 * none stands, which CustomerBlockController writes, and the staff's notes on the address newest
 * first, each with its author's name and its instant, which CustomerNoteController writes.
 *
 * A customer's page is headed by the customer and lists the address's bookings through the Bookings
 * list's own reader, filtered to the address and in that list's order, each through
 * BookingPayload::admin. The booking dialog over it is the calendars' shape: asked for by a booking
 * query parameter as a partial reload of the detail prop with the URL preserved,
 * CalendarPage::detail building it with the actions this viewer may take now; an action posts to
 * the dialog's own routes, whose redirect back lands this page again with every prop re-read. An
 * address no booking was made under is the shell's 404, rendered by NotFoundController as the
 * catch-all renders it, so no rail entry is lit.
 *
 * The erasure, is the one write here and is not behind the subscription gate: a data right is no
 * configuration. CustomerErasure runs it in one transaction with the principal's actor on the
 * trail. A booking of the address still live refuses it as the 409 envelope with the sentence the
 * page's erasure prop already showed, a busy lock as the 503 envelope, an address no booking
 * carries as the framework's 404, which the client words in place; a landed erasure leaves for the
 * Customers list with its flash, the address gone from it.
 *
 * The export, is outside the subscription gate for the same reason. CustomerExport reads the
 * address's bookings whole and writes their trail rows with the principal's actor in one
 * transaction, and the committed document is streamed as an attachment, JSON in UTF-8 named
 * customer-{slug}-{date}.json, never cached and never sniffed as anything else; an address no
 * booking carries is the framework's 404, which the page's button words in its toast.
 *
 * The list's Download CSV, GET /admin/customers/csv, reads the list's own query and answers what
 * the list shows under its search, every matching address and not the page on screen, through
 * CustomerRecords::every and the same profiles the rows carry, written by CustomerCsv and
 * CsvDownload: text/csv in UTF-8 behind a byte order mark, attached as customers-{date}.csv on the
 * tenant's today, never cached. It reads, so it sits where the list does, under manageBookings and
 * outside the subscription gate.
 */
final readonly class CustomerController
{
    /** The section of lang/{locale}/admin both pages read, the customers' own words. */
    public const string SECTION = 'customers';

    /** The erasure's flash, a key of customers.php's flashes worded in the request's language. */
    public const string ERASED = 'admin/customers.flashes.erased';

    /** The erasure's refusal while bookings of the address are live, a choice by their count. */
    public const string LIVE = 'admin/customers.refusals.live';

    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private CustomerRecords $customers,
        private BookingRecords $records,
        private CalendarPage $page,
        private CalendarRoster $roster,
        private NotFoundController $notFound,
        private CustomerErasure $erasure,
        private CustomerExport $export,
        private CustomerProfiles $profiles,
        private CustomerNotes $notes,
        private Clock $clock,
        private SettingsReader $settings,
    ) {}

    public function index(CustomerRequest $request): Response
    {
        $tenant = $this->current->get();
        $manage = $this->viewer($request)->holds(Permission::ManageBookings);
        $searchable = BookingPayload::searchable($manage);
        $filter = $request->filter()->searchingIn($searchable);
        // The list's words; the dispatched request is the one the shared props read, the form
        // request a copy of it.
        AdminStrings::need(request(), self::SECTION);

        return Inertia::render('Customers', [
            'filters' => $filter->toQuery(),
            'searchable' => $searchable,
            'customers' => function () use ($tenant, $filter, $manage): array {
                $page = $this->customers->page($tenant, $filter);
                $profiles = $this->profiles->of($tenant, array_map(fn (CustomerRecord $customer): string => $customer->email, $page['customers']));

                return [
                    'rows' => array_map(fn (CustomerRecord $customer): array => CustomerPayload::admin($customer, $manage, $profiles[$customer->email] ?? CustomerProfile::none()), $page['customers']),
                    'total' => $page['total'],
                    'page' => $filter->page,
                    'page_size' => $filter::PAGE_SIZE,
                ];
            },
        ]);
    }

    public function csv(CustomerRequest $request): StreamedResponse
    {
        $tenant = $this->current->get();
        $manage = $this->viewer($request)->holds(Permission::ManageBookings);
        $filter = $request->filter()->searchingIn(BookingPayload::searchable($manage));
        $customers = $this->customers->every($tenant, $filter);
        $profiles = $this->profiles->of($tenant, array_map(fn (CustomerRecord $customer): string => $customer->email, $customers));
        $settings = $this->settings->read($tenant);
        $rows = CustomerCsv::rows($customers, $profiles, $manage, $filter->search, $tenant->zone(), $settings->dateFormat, $settings->timeFormat);
        $name = sprintf('customers-%s.csv', $this->clock->now()->setTimezone($tenant->zone())->format('Y-m-d'));

        return CsvDownload::of($rows, $name);
    }

    /**
     * The request the router dispatched rides ahead of the form request, which is a copy of it:
     * the 404 marks the dispatched one, which is the one the shared props read.
     */
    public function show(Request $dispatched, CustomerRequest $request, string $email): Response|HttpResponse
    {
        $tenant = $this->current->get();
        $customer = $this->customers->one($tenant, $email);

        if ($customer === null) {
            return ($this->notFound)($dispatched);
        }

        // The page's own words, and the booking dialog's, the bookings section.
        AdminStrings::need($dispatched, self::SECTION, BookingListController::SECTION);
        $viewer = $this->viewer($request);
        $manage = $viewer->holds(Permission::ManageBookings);
        $filter = new BookingFilter(page: $request->page(), email: $customer->email);
        $profile = $this->profiles->one($tenant, $customer->email);

        return Inertia::render('Customer', [
            'customer' => CustomerPayload::admin($customer, $manage, $profile),
            'profile' => ['tags' => $profile->tags, 'birthday' => $profile->birthday, 'language' => $profile->language],
            'earliest_birthday' => CustomerProfileRequest::EARLIEST,
            'block' => ['blocked_at' => $profile->blockedAt === null ? null : BookingPayload::instant($profile->blockedAt), 'reason' => $profile->blockedReason],
            'notes' => fn (): array => array_map(fn (CustomerNote $note): array => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $note->author,
                'created_at' => BookingPayload::instant($note->createdAt),
            ], $this->notes->of($tenant, $customer->email)),
            'spent' => fn (): array => $this->customers->spent($tenant, $customer->email),
            'members' => fn (): int => $this->roster->members($tenant),
            'bookings' => function () use ($tenant, $filter, $manage): array {
                $page = $this->records->page($tenant, $filter);

                return [
                    'rows' => array_map(fn (BookingRecord $record): array => BookingPayload::admin($record, $manage), $page['records']),
                    'total' => $page['total'],
                    'page' => $filter->page,
                    'page_size' => $filter::PAGE_SIZE,
                ];
            },
            'detail' => fn (): ?array => $this->page->detail($tenant, $request->booking(), $viewer, $this->roster->ownRow($tenant, $viewer->ownStaffUserId())['id'] ?? null, null),
            'erasure' => function () use ($tenant, $customer): array {
                $live = $this->erasure->liveCount($tenant, $customer->email);

                return ['refusal' => $live === 0 ? null : self::liveSentence($live)];
            },
        ]);
    }

    /**
     * Erases the customer the address names, the address matched as the page's route
     * matches it.
     */
    public function erase(Request $request, string $email): RedirectResponse|JsonResponse
    {
        $actor = $this->viewer($request)->actor();

        try {
            $this->erasure->erase($this->current->get(), $email, $actor);
        } catch (CustomerUnknown) {
            throw new NotFoundHttpException;
        } catch (CustomerLive $live) {
            return (new ErrorEnvelope(CustomerLive::CODE, 409, self::liveSentence($live->live)))->toResponse();
        } catch (Busy $busy) {
            return ErrorEnvelope::for($busy)->toResponse();
        }

        $line = __(self::ERASED);
        $request->session()->flash('flash', ['kind' => 'success', 'message' => is_string($line) ? $line : self::ERASED]);

        return redirect()->to(AdminSession::at($request, AdminSession::PREFIX.'/customers'));
    }

    /**
     * Downloads the customer's data the address names, the address matched as the page's route
     * matches it. An address that names no customer answers the shell's NotFound page, as show()
     * does, to a page visit, and the bare 404 to a JSON ask.
     */
    public function export(Request $request, string $email): StreamedResponse|HttpResponse
    {
        $actor = $this->viewer($request)->actor();

        try {
            $document = $this->export->document($this->current->get(), $email, $actor);
        } catch (CustomerUnknown) {
            if ($request->expectsJson()) {
                throw new NotFoundHttpException;
            }

            return ($this->notFound)($request);
        }

        return response()->streamDownload(function () use ($document): void {
            echo $document['json'];
        }, $document['file'], [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function viewer(Request $request): Principal
    {
        return $this->session->principal($request) ?? throw new LogicException('The customers screen rendered without a principal; its route declares no permission.');
    }

    /**
     * The erasure's refusal for a count of live bookings, one or more, worded in the request's
     * language: what the page shows before any press and the 409 answers as its detail. The
     * integer chooses the form and fills :count in the language's number format, as every count
     * in a sentence of the admin is.
     */
    public static function liveSentence(int $live): string
    {
        $formatted = (new NumberFormatter(app()->getLocale(), NumberFormatter::DECIMAL))->format($live);

        return trans_choice(self::LIVE, $live, ['count' => is_string($formatted) ? $formatted : (string) $live]);
    }
}
