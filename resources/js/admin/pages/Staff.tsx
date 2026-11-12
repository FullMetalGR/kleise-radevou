import { Head, router, usePage } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { ListDetail, SettledList } from '@/components/settled-list';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { type BookingLink, copyLinkOf } from '@/lib/booking-link';
import { clockOf, formatCount, type TenantClock } from '@/lib/format';
import type { FieldControl } from '@/components/ui/field';
import { type OneWrite, useOneWrite } from '@/lib/one-write';
import { useShell } from '@/lib/strings';
import { type Failure, owningFailure as owning } from '@/lib/visits';
import { ImageSlot, type ImageSlotWords } from '@/settings/ImageSlot';
import { useColourByWrite } from '@/calendar/colour-by';
import { Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import type { StaffColour } from '@/lib/hue';
import { BlackoutsCard, closureBody, closurePath, type ClosureWho, type Draft, type InFlight } from '@/staff/BlackoutsCard';
import { ColoursCard } from '@/staff/ColoursCard';
import { FeatureGone } from '@/shell/NarrowedBanner';
import { type Blackout, blankForm, type CatalogService, type ClosureLine, type Form, formOf, inactiveCount, membersShown, patchOf, searchRows, type StaffMember, type TenantUser, type View, visibleRows } from '@/staff/model';
import { type PhotoLimit, SHIFTS_PATH, StaffDangerZone, StaffForm } from '@/staff/StaffForm';
import { StaffTable } from '@/staff/StaffTable';
import { StaffView } from '@/staff/StaffView';
import { type StaffKey, StaffProvider, type StaffWords, staffWordsOf, useStaff } from '@/staff/words';
import { useToasts } from '@/toast/toasts';
import type { SharedProps, StaffStrings } from '@/types';

/*
 * The Staff screen, over the list-detail scaffold: one page for the list with the tenant-wide
 * closures, the create route and the detail route, every one carrying every member whole, the
 * detail resolved from them on the client.
 *
 * Nothing is optimistic. A save sends the row and its links as one write and shows
 * one toast; a landed save on a persisted row re-seeds the form from the server's answer, and
 * only that does: the form is keyed on the row and a seed count this page's own landed saves
 * bump, so a background refetch carrying the same row never rebuilds it and an edit in
 * progress is never clobbered. Saving a new member navigates to its page. The closures are
 * read from the props, so adding or removing one refreshes the card and leaves the form alone.
 *
 * The photo, on a persisted member who can have one, is its own write on its own two routes,
 * apart from Save as the logo is, and the page holds one busy state over Save and the photo's
 * two writes: the pressed control alone is busy, the others are held with the sentence of the
 * write in flight. A landed upload rekeys the chooser and the answer's photo arrives through the
 * props; the form is not re-seeded by it, so an edit in progress survives. A refusal is its
 * sentence in an error toast.
 *
 * The one code the admin branches on, 9.2: referenced, from the delete guard, is the inline
 * warning beside the button that earned it, whose remedy patches the status and leaves for the
 * list, where the outcome reads under the inactive toggle.
 *
 * Without the staff feature, the screen offers no control the server refuses and keeps every read.
 * Whether anything is editable reads the entitlements prop, as the rail does, never a lapse flag or
 * a row count. The list keeps its rows, its toggle and the tenant-wide closures card, which the
 * floor keeps and which still adds and removes; New staff member is gone, and a member's page is
 * StaffView, every value as stored but the implicit member's Title, Bio and photo, which it
 * withholds as the form does, and no Save, Delete or closures form. Both sit under one notice in
 * the banner's own words, which the screen leaves to the banner when the narrowing record already
 * names the feature, so the sentence is never on screen twice; on a free tenant those words name
 * platform support and link nothing, its plan being the console's (the shared free prop). A floor
 * whose roster holds the implicit row alone gets the closures card alone on every route of the
 * screen, headed Closures as the rail's entry is without the feature; any other row beside it, an
 * inactive one included, gives the read-only list, still headed Staff so its members are named for
 * what they are, since a sold member stays readable. The card itself is titled Closed days on every
 * branch, so the floor's screen never reads Closures twice.
 *
 * The implicit row always exists, so the list's empty panel is only ever a roster whose every
 * row is inactive, the implicit one included, with the feature or without: it says so and
 * points at the toggle beside it, which names the count.
 *
 * Every word is a key of the staff section, which StaffController names beside the shell's on every
 * route of the screen, the floor's included, provided to the table, the form, the read-only page
 * and the closures cards under the page.
 */

type Props = SharedProps & {
    view: View;
    staff: StaffMember[];
    services: CatalogService[];
    users: TenantUser[];
    /** Every closure today onward, soonest first, a run folded into one line, each naming its member or none. */
    closures: ClosureLine[];
    /** Every named member's booking link, BookingLinks on the server, for the detail's Copy booking link. */
    links: BookingLink[];
    /** The largest photo, its megabytes and their spelling in words, named by the photo's help. */
    photo_limit: PhotoLimit;
    /** The staff section beside the shell's. */
    strings: SharedProps['strings'] & { staff?: StaffStrings };
};

const LIST = '/admin/staff';

const LIST_ERROR = 'page.list_error' satisfies StaffKey;

const NOT_FOUND = 'page.not_found' satisfies StaffKey;

const SAVE_ERROR = 'page.save_error' satisfies StaffKey;

const DELETE_ERROR = 'page.delete_error' satisfies StaffKey;

const CLOSURE_ERROR = 'page.closure_error' satisfies StaffKey;

const PHOTO_ERROR = 'page.photo_error' satisfies StaffKey;

/** The closures card's title without the feature, under the screen's heading whichever it is. */
const CLOSED_DAYS = 'page.closed_days' satisfies StaffKey;

/** The closures card's title on the list with the feature. */
const CLOSURES = 'page.closures' satisfies StaffKey;

const COLOUR_ERROR = 'colours.error' satisfies StaffKey;

/** The staff photo's four words, each a key read in the page's words. */
const PHOTO_WORDS = { none: 'page.photo_none', upload: 'page.photo_upload', replace: 'page.photo_replace', remove: 'page.photo_remove' } as const satisfies Record<keyof ImageSlotWords, StaffKey>;

type Action = 'save' | 'photo-upload' | 'photo-remove';

const WAIT = {
    save: 'page.wait_save',
    'photo-upload': 'page.wait_photo_upload',
    'photo-remove': 'page.wait_photo_remove',
} as const satisfies Record<Action, StaffKey>;

/** The waits in the page's words, as the one write holds the other controls with them. */
function waitsOf(words: StaffWords): Record<Action, string> {
    return { save: words.text(WAIT.save), 'photo-upload': words.text(WAIT['photo-upload']), 'photo-remove': words.text(WAIT['photo-remove']) };
}

/** The photo control's words in the page's words. */
function photoWordsOf(words: StaffWords): ImageSlotWords {
    return { none: words.text(PHOTO_WORDS.none), upload: words.text(PHOTO_WORDS.upload), replace: words.text(PHOTO_WORDS.replace), remove: words.text(PHOTO_WORDS.remove) };
}

const REFERENCED = 'referenced';

/** A refusal in the envelope's sentence, or the write's own fallback where no answer came. */
function plainly(fallback: string): (failure: Failure) => string {
    return (failure) => failure.sentence ?? fallback;
}

function idOf(view: View): number | 'new' | null {
    return view.kind === 'detail' ? view.id : view.kind === 'create' ? 'new' : null;
}

/** Who on the list's closures card: the active named members it offers, and every member's name a line may carry. */
function whoOf(members: readonly StaffMember[]): ClosureWho {
    return {
        offered: members.filter((member) => !member.is_implicit && member.status === 'active').map((member) => ({ id: member.id, name: member.name })),
        names: new Map(members.map((member) => [member.id, member.name])),
    };
}

export default function Staff() {
    const { props } = usePage<Props>();
    const clock = clockOf(props);
    const toasts = useToasts();
    const shell = useShell();
    const words = staffWordsOf(props.strings, shell.language);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    const [showInactive, setShowInactive] = useState(false);
    const [query, setQuery] = useState('');
    const write = useOneWrite<Action>(waitsOf(words));
    const { inFlight, waitFor, visit } = write;
    const [seed, setSeed] = useState(0);
    const [photoUploads, setPhotoUploads] = useState(0);
    const id = idOf(props.view);
    const editable = props.entitlements.includes('staff');

    const go = (path: string) => {
        const owned = owning(() => setError(true), shell);

        router.get(
            path,
            {},
            {
                preserveState: true,
                onStart: () => {
                    setLoading(true);
                    owned.onStart();
                },
                onSuccess: () => setError(false),
                onFinish: () => {
                    setLoading(false);
                    owned.onFinish();
                },
            },
        );
    };

    const create = (form: Form) => visit('save', plainly(words.text(SAVE_ERROR)), () => undefined, (options) => router.post(LIST, patchOf(form, false), options));

    const update = (row: StaffMember) => (form: Form) =>
        visit(
            'save',
            plainly(words.text(SAVE_ERROR)),
            () => setSeed((count) => count + 1),
            (options) => router.put(`${LIST}/${row.id}`, patchOf(form, row.is_implicit), options),
        );

    const photo = (row: StaffMember) => photoSlot(row, write, photoUploads, () => setPhotoUploads((count) => count + 1), words);

    // Without the feature the card is the business's alone, as the per-member routes answer
    // not-found.
    const closures = () => (
        <Closures
            pathOf={editable ? closurePath : () => closurePath(null)}
            blackouts={editable ? props.closures : props.closures.filter((line) => line.staff_id === null)}
            who={editable ? whoOf(props.staff) : null}
            clock={clock}
            weekStart={props.settings.week_start}
            granularity={props.settings.granularity}
            title={words.text(editable ? CLOSURES : CLOSED_DAYS)}
            idPrefix="blackout"
            onFailure={(failure) => toasts.push('error', failure.sentence ?? words.text(CLOSURE_ERROR))}
        />
    );

    if (!editable && !membersShown(props.staff)) {
        return (
            <StaffProvider words={words}>
                <Head title={words.text('page.floor_title')} />
                <div className="admin-staff-screen admin-staff-screen--floor flex flex-col gap-4">
                    <h1 className="text-2xl font-semibold">{words.text('page.floor_title')}</h1>
                    {closures()}
                </div>
            </StaffProvider>
        );
    }

    const readOnly =
        editable || props.narrowed.includes('staff') ? null : (
            <Notice kind="info" className="admin-staff-readonly">
                <FeatureGone slug="staff" manageBilling={props.permissions.includes('manageBilling')} free={props.free} block="admin-staff-readonly" />
            </Notice>
        );

    if (id === null) {
        const rows = searchRows(visibleRows(props.staff, showInactive), query);
        const inactive = inactiveCount(props.staff);
        const colours = editable && props.permissions.includes('manageSettings');

        return (
            <StaffProvider words={words}>
                <Head title={words.text('page.title')} />
                <div className="admin-staff-screen flex flex-col gap-4">
                    <div className="flex items-center justify-between gap-4">
                        <h1 className="text-2xl font-semibold">{words.text('page.title')}</h1>
                        {editable && (
                            <div className="flex items-center gap-2">
                                <Button variant="secondary" className="admin-staff-shifts" onClick={() => router.get(SHIFTS_PATH)}>
                                    {words.text('page.shifts')}
                                </Button>
                                <Button variant="primary" className="admin-staff-new" onClick={() => go(`${LIST}/new`)}>
                                    {words.text('page.new')}
                                </Button>
                            </div>
                        )}
                    </div>
                    <p className="admin-staff-lead text-sm text-muted-foreground">{words.text('page.lead')}</p>
                    {readOnly}
                    <div className="flex flex-wrap items-end gap-4">
                        <Field id="staff-search" label={words.text('page.search')} className="w-full max-w-96">
                            {(control) => <TextInput {...control} type="search" className="admin-staff-search" placeholder={words.text('page.search_placeholder')} value={query} onChange={(event) => setQuery(event.target.value)} />}
                        </Field>
                        <div className="admin-staff-toggle flex h-8 items-center gap-2 text-sm">
                            <input type="checkbox" id="staff-show-inactive" className="admin-staff-toggle__tick size-4 accent-primary" checked={showInactive} onChange={(event) => setShowInactive(event.target.checked)} />
                            <label htmlFor="staff-show-inactive">{words.text('page.show_inactive', { count: formatCount(inactive, words.locale) })}</label>
                        </div>
                    </div>
                    <SettledList
                        count={rows.length}
                        state={{ loading, error }}
                        errorSentence={words.text(LIST_ERROR)}
                        empty={
                            query.trim() !== '' ? (
                                <p className="admin-staff-no-match rounded-xl border p-6 text-center text-muted-foreground">{words.text('page.no_match')}</p>
                            ) : (
                                <div className="admin-staff-empty rounded-xl border p-6 text-center">
                                    <p className="font-medium">{words.text('page.none')}</p>
                                    <p className="text-muted-foreground">{words.text('page.none_sentence')}</p>
                                </div>
                            )
                        }
                    >
                        <StaffTable rows={rows} clock={clock} onSelect={(selected) => go(`${LIST}/${selected}`)} />
                    </SettledList>
                    {closures()}
                    {colours && <Colours colourBy={props.settings.colour_by} members={props.staff.filter((member) => !member.is_implicit && member.status === 'active')} words={words} />}
                </div>
            </StaffProvider>
        );
    }

    return (
        <StaffProvider words={words}>
            <Head title={words.text(id === 'new' ? 'page.new' : 'page.detail_title')} />
            <div className="admin-staff-detail">
                <ListDetail
                    rows={props.staff}
                    state={{ loading, error }}
                    id={id}
                    loadingLabel={words.text('page.loading')}
                    errorSentence={words.text(LIST_ERROR)}
                    notFoundSentence={words.text(NOT_FOUND)}
                    backLabel={words.text('page.back')}
                    listHref={LIST}
                    onBack={() => go(LIST)}
                    create={
                        editable && (
                            <Editor
                                key="new"
                                row={null}
                                initial={blankForm()}
                                users={props.users}
                                services={props.services}
                                clock={clock}
                                busy={inFlight === 'save'}
                                wait={waitFor('save')}
                                photo={null}
                                photoLimit={props.photo_limit}
                                onSave={create}
                                onBack={() => go(LIST)}
                                exceptions={null}
                                danger={null}
                                copyLink={null}
                            />
                        )
                    }
                >
                    {(row) =>
                        !editable ? (
                            <StaffView member={row} services={props.services} users={props.users} clock={clock} notice={readOnly} listHref={LIST} onBack={() => go(LIST)} copyLink={copyLinkOf(props.links, row.id, props.permissions)} />
                        ) : (
                            <Editor
                                key={`${row.id}:${seed}`}
                                row={row}
                                initial={formOf(row)}
                                users={props.users}
                                services={props.services}
                                clock={clock}
                                busy={inFlight === 'save'}
                                wait={waitFor('save')}
                                photo={photo(row)}
                                photoLimit={props.photo_limit}
                                onSave={update(row)}
                                onBack={() => go(LIST)}
                                exceptions={
                                    <Closures
                                        pathOf={() => closurePath(row.id)}
                                        blackouts={row.blackouts}
                                        who={null}
                                        clock={clock}
                                        weekStart={props.settings.week_start}
                                        granularity={props.settings.granularity}
                                        title={words.text('page.exceptions')}
                                        idPrefix="exception"
                                        onFailure={(failure) => toasts.push('error', failure.sentence ?? words.text(CLOSURE_ERROR))}
                                    />
                                }
                                danger={<RowDanger row={row} onDeactivated={() => go(LIST)} />}
                                copyLink={copyLinkOf(props.links, row.id, props.permissions)}
                            />
                        )
                    }
                </ListDetail>
            </div>
        </StaffProvider>
    );
}

/**
 * The photo control of one persisted member in the field shell: its own write on its own two
 * routes, held by the page's one busy state, the chooser rekeyed by each landed upload.
 */
function photoSlot(row: StaffMember, write: OneWrite<Action>, uploads: number, onUploaded: () => void, words: StaffWords): (control: FieldControl) => ReactNode {
    const path = `${LIST}/${row.id}/photo`;

    return (control) => (
        <ImageSlot
            key={uploads}
            block="admin-staff-photo"
            words={photoWordsOf(words)}
            preview="size-16 rounded-full object-cover"
            control={control}
            image={row.photo}
            uploading={write.inFlight === 'photo-upload'}
            removing={write.inFlight === 'photo-remove'}
            wait={write.pending}
            onUpload={(file) => write.visit('photo-upload', plainly(words.text(PHOTO_ERROR)), onUploaded, (options) => router.post(path, { photo: file }, { ...options, forceFormData: true }))}
            onRemove={() => write.visit('photo-remove', plainly(words.text(PHOTO_ERROR)), () => undefined, (options) => router.delete(path, options))}
        />
    );
}

type EditorProps = {
    row: StaffMember | null;
    initial: Form;
    users: readonly TenantUser[];
    services: readonly CatalogService[];
    clock: TenantClock;
    busy: boolean;
    wait: string | null;
    photo: ((control: FieldControl) => ReactNode) | null;
    photoLimit: PhotoLimit;
    onSave: (form: Form) => void;
    onBack: () => void;
    exceptions: ReactNode;
    danger: ReactNode;
    copyLink: ReactNode;
};

/**
 * The form's edits, seeded once from the row and kept until the page's own landed save rekeys it.
 * The implicit member stands for the business and has no face, title or bio, so its page carries
 * no photo and the form renders neither field.
 */
function Editor({ row, initial, users, services, clock, busy, wait, photo, photoLimit, onSave, onBack, exceptions, danger, copyLink }: EditorProps) {
    const words = useStaff();
    const [form, setForm] = useState<Form>(initial);

    return (
        <StaffForm
            form={form}
            onChange={setForm}
            creating={row === null}
            implicit={row?.is_implicit === true}
            title={row === null ? words.text('page.new') : row.name}
            status={row === null ? null : row.status}
            users={users}
            services={services}
            clock={clock}
            pattern={row?.pattern ?? null}
            busy={busy}
            wait={wait}
            photo={row?.is_implicit === true ? null : photo}
            photoLimit={photoLimit}
            onSave={() => onSave(form)}
            onBack={onBack}
            listHref={LIST}
            exceptions={exceptions}
            danger={danger}
            copyLink={copyLink}
        />
    );
}

type ClosuresProps = {
    /** The route a line or a draft is written to, by its member, null for the whole business. */
    pathOf: (staffId: number | null) => string;
    blackouts: readonly (Blackout & { staff_id?: number | null })[];
    who: ClosureWho | null;
    clock: TenantClock;
    weekStart: number;
    granularity: number;
    title: string;
    idPrefix: string;
    onFailure: (failure: Failure) => void;
};

/**
 * One closures card and its two writes, each to its scope's route, the business's or a member's,
 * by the line or by Who: an add is a POST and a removal a DELETE carrying the dates and the
 * window seen, a folded run's first and last, never a row id. The card
 * is told which action is in flight, and for which row, so the pressed control alone shows
 * busy. A landed add rekeys the card so its draft clears; a refused one keeps it, with the
 * sentence toasted.
 */
function Closures({ pathOf, blackouts, who, clock, weekStart, granularity, title, idPrefix, onFailure }: ClosuresProps) {
    const shell = useShell();
    const [inFlight, setInFlight] = useState<InFlight>(null);
    const [added, setAdded] = useState(0);

    const visit = (method: 'post' | 'delete', blackout: Blackout & { staff_id?: number | null }, onSuccess: () => void) => {
        const path = pathOf(blackout.staff_id ?? null);
        const owned = owning(onFailure, shell);
        const options = {
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                setInFlight(method === 'post' ? { action: 'add' } : { action: 'remove', blackout });
                owned.onStart();
            },
            onSuccess,
            onFinish: () => {
                setInFlight(null);
                owned.onFinish();
            },
        };

        if (method === 'post') {
            router.post(path, closureBody(blackout), options);
        } else {
            router.delete(path, { ...options, data: closureBody(blackout) });
        }
    };

    return (
        <BlackoutsCard
            key={added}
            title={title}
            idPrefix={idPrefix}
            blackouts={blackouts}
            who={who}
            clock={clock}
            weekStart={weekStart}
            granularity={granularity}
            inFlight={inFlight}
            onAdd={(draft: Draft) => visit('post', draft, () => setAdded((count) => count + 1))}
            onRemove={(blackout) => visit('delete', blackout, () => undefined)}
        />
    );
}

/**
 * The Calendar colours card and its two writes: Colour bookings by through the Calendar's own
 * write, and a member's own colour on its route, each a redirect back whose answer lands the new
 * value in the props; one busy state holds the card while either is in flight.
 */
function Colours({ colourBy, members, words }: { colourBy: SharedProps['settings']['colour_by']; members: readonly StaffMember[]; words: StaffWords }) {
    const toasts = useToasts();
    const shell = useShell();
    const byWrite = useColourByWrite(words.text(COLOUR_ERROR));
    const [writing, setWriting] = useState(false);

    const colour = (id: number, picked: StaffColour | null) => {
        const owned = owning((failure) => toasts.push('error', failure.sentence ?? words.text(COLOUR_ERROR)), shell);

        router.put(
            `${LIST}/${id}/colour`,
            { colour: picked },
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => {
                    setWriting(true);
                    owned.onStart();
                },
                onFinish: () => {
                    setWriting(false);
                    owned.onFinish();
                },
            },
        );
    };

    return <ColoursCard colourBy={colourBy} members={members} busy={byWrite.busy || writing} onColourBy={byWrite.write} onColour={colour} />;
}

/**
 * The danger zone of one persisted row, mounted under the row's Editor so the referenced
 * notice lives and dies with the row. The remedy patches the status alone and, once landed,
 * leaves for the list. The implicit row offers no delete at all.
 */
function RowDanger({ row, onDeactivated }: { row: StaffMember; onDeactivated: () => void }) {
    const toasts = useToasts();
    const shell = useShell();
    const words = useStaff();
    const [deleting, setDeleting] = useState(false);
    const [deactivating, setDeactivating] = useState(false);
    const [conflict, setConflict] = useState<string | null>(null);

    const remove = () => {
        const owned = owning((failure) => {
            if (failure.status === 409 && failure.code === REFERENCED) {
                setConflict(failure.sentence ?? words.text(DELETE_ERROR));

                return;
            }

            toasts.push('error', failure.sentence ?? words.text(DELETE_ERROR));
        }, shell);

        setConflict(null);
        router.delete(`${LIST}/${row.id}`, {
            preserveScroll: true,
            onStart: () => {
                setDeleting(true);
                owned.onStart();
            },
            onFinish: () => {
                setDeleting(false);
                owned.onFinish();
            },
        });
    };

    const deactivate = () => {
        const owned = owning((failure) => toasts.push('error', failure.sentence ?? words.text(SAVE_ERROR)), shell);

        router.put(
            `${LIST}/${row.id}`,
            { status: 'inactive' },
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => {
                    setDeactivating(true);
                    owned.onStart();
                },
                onSuccess: () => {
                    setConflict(null);
                    onDeactivated();
                },
                onFinish: () => {
                    setDeactivating(false);
                    owned.onFinish();
                },
            },
        );
    };

    return <StaffDangerZone implicit={row.is_implicit} conflict={conflict} deleting={deleting} deactivating={deactivating} onDelete={remove} onDeactivate={deactivate} />;
}
