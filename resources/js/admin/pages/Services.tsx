import { Head, router, usePage } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { ListDetail, SettledList } from '@/components/settled-list';
import { Button } from '@/components/ui/button';
import { type BookingLink, copyLinkOf } from '@/lib/booking-link';
import { clockOf, type TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { owningFailure as owning } from '@/lib/visits';
import { blankForm, createLinks, type Form, formOf, inactiveCount, linkedMembers, type LinkWrite, linkWrites, type Member, patchOf, type Service, type View, visibleRows } from '@/services/model';
import { saving, useSaving } from '@/services/saving';
import { DangerZone, type Roster, ServiceForm } from '@/services/ServiceForm';
import { ServicesTable } from '@/services/ServicesTable';
import { type ServicesKey, ServicesProvider, servicesWordsOf, useServices } from '@/services/words';
import { staffShown } from '@/bookings/model';
import { useToasts } from '@/toast/toasts';
import type { ServicesStrings, SharedProps } from '@/types';

/*
 * The Services screen, over the list-detail scaffold: one page for the catalog, the create route
 * and the detail route, every one carrying the whole catalog, the detail resolved from it on the
 * client. The inactive toggle is a view filter and client state, so it does not survive a reload.
 *
 * Every visit the page makes owns its failure. A navigation that fails is the list's error
 * notice. A write that fails is the server's own sentence as an error toast, except the one code
 * the admin branches on, 9.2: referenced, from the delete guard, which is the inline warning
 * beside the button that earned it, with its remedy. That notice is state of the row's own danger
 * zone, mounted under the Editor the page keys on the row, so it lives and dies with the row:
 * another row, the create route and the same row reopened all start without it. Nothing is
 * optimistic: a success arrives as the redirect's props and flash, and the form is rebuilt from
 * the row the server answered.
 *
 * Save sends the service patch, then, one at a time, each changed staff link as a replace-all
 * list for that member; an untouched checklist sends none. Creating lands on the new row's page
 * and sends its links from there, the busy state carried across the mount. A new service on a
 * roster of one row starts with that row ticked, the link the server writes on such a create
 * (createLinks), so an untouched checklist still sends none.
 *
 * Every word is a key of the services section, which ServiceController names beside the shell's,
 * provided to the table and the form under the page.
 */

type Props = SharedProps & {
    view: View;
    services: Service[];
    categories: string[];
    roster: Member[];
    /** Every service's booking link, BookingLinks on the server, for the detail's Copy booking link. */
    links: BookingLink[];
    /** The services section beside the shell's. */
    strings: SharedProps['strings'] & { services?: ServicesStrings };
};

const LIST = '/admin/services';

const LIST_ERROR = 'page.list_error' satisfies ServicesKey;

const NOT_FOUND = 'page.not_found' satisfies ServicesKey;

const SAVE_ERROR = 'page.save_error' satisfies ServicesKey;

const DELETE_ERROR = 'page.delete_error' satisfies ServicesKey;

const REFERENCED = 'referenced';

function idOf(view: View): number | 'new' | null {
    return view.kind === 'detail' ? view.id : view.kind === 'create' ? 'new' : null;
}

export default function Services() {
    const { props } = usePage<Props>();
    const clock = clockOf(props);
    const toasts = useToasts();
    const shell = useShell();
    const words = servicesWordsOf(props.strings, shell.language);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    const [showInactive, setShowInactive] = useState(false);
    const busy = useSaving();
    const id = idOf(props.view);
    const staff = staffShown(props.entitlements, props.roster.length, null);
    // Who performs a service changes with the staff feature alone: without it the checklist shows
    // and sends nothing.
    const links = props.entitlements.includes('staff');

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

    /**
     * The staff link writes, one at a time, each a redirect back that re-reads the roster. A
     * write that fails ends the chain and says which member's links did not land; the ones
     * before it stay landed.
     */
    const writeLinks = (queue: readonly LinkWrite[]) => {
        const [write, ...rest] = queue;

        if (write === undefined) {
            saving.set(false);

            return;
        }

        const owned = owning((failure) => {
            toasts.push('error', words.text('page.links_failed', { staff: write.name, reason: failure.sentence ?? words.text(SAVE_ERROR) }));
            saving.set(false);
        }, shell);

        router.put(
            `/admin/staff/${write.staffId}/services`,
            { service_ids: write.service_ids },
            {
                preserveState: true,
                preserveScroll: true,
                onStart: owned.onStart,
                onSuccess: () => writeLinks(rest),
                onFinish: owned.onFinish,
            },
        );
    };

    const save = (form: Form, staged: readonly number[], row: Service | null) => {
        if (saving.get()) {
            return;
        }

        const creating = row === null;
        const currency = creating ? props.tenant.currency : row.currency;
        const patch = patchOf(form, currency, creating);
        const owned = owning((failure) => {
            toasts.push('error', failure.sentence ?? words.text(SAVE_ERROR));
            saving.set(false);
        }, shell);
        const options = {
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                saving.set(true);
                owned.onStart();
            },
            onFinish: owned.onFinish,
        };

        if (creating) {
            router.post(LIST, patch, {
                ...options,
                onSuccess: (page) => {
                    const landed = page.props as Partial<Props>;
                    const created = landed.view !== undefined && landed.view.kind === 'detail' ? landed.view.id : null;

                    if (created === null || landed.roster === undefined) {
                        saving.set(false);

                        return;
                    }

                    writeLinks(links ? linkWrites(staged, landed.roster, created) : []);
                },
            });

            return;
        }

        router.put(`${LIST}/${row.id}`, patch, {
            ...options,
            onSuccess: () => writeLinks(links ? linkWrites(staged, props.roster, row.id) : []),
        });
    };

    if (id === null) {
        const rows = visibleRows(props.services, showInactive);
        const inactive = inactiveCount(props.services);

        return (
            <ServicesProvider words={words}>
                <Head title={words.text('page.title')} />
                <div className="admin-services-screen flex flex-col gap-4">
                    <div className="flex items-center justify-between gap-4">
                        <h1 className="text-2xl font-semibold">{words.text('page.title')}</h1>
                        <Button variant="primary" className="admin-services-new" onClick={() => go(`${LIST}/new`)}>
                            {words.text('page.new')}
                        </Button>
                    </div>
                    <div className="admin-services-toggle flex items-center gap-2 text-sm">
                        <input type="checkbox" id="services-show-inactive" className="admin-services-toggle__tick" checked={showInactive} onChange={(event) => setShowInactive(event.target.checked)} />
                        <label htmlFor="services-show-inactive">{words.text('page.show_inactive', { count: String(inactive) })}</label>
                    </div>
                    <SettledList
                        count={rows.length}
                        state={{ loading, error }}
                        errorSentence={words.text(LIST_ERROR)}
                        empty={
                            <div className="admin-services-empty rounded-xl border p-6 text-center">
                                <p className="font-medium">{words.text('page.none')}</p>
                                <p className="text-muted-foreground">{words.text('page.none_sentence')}</p>
                            </div>
                        }
                    >
                        <ServicesTable rows={rows} onSelect={(selected) => go(`${LIST}/${selected}`)} />
                    </SettledList>
                </div>
            </ServicesProvider>
        );
    }

    const roster = staff ? { loading, members: props.roster, editable: links, shifts: props.permissions.includes('manageSettings') } : null;

    return (
        <ServicesProvider words={words}>
            <Head title={words.text(id === 'new' ? 'page.new' : 'page.detail_title')} />
            <div className="admin-service-screen">
                <ListDetail
                    rows={props.services}
                    state={{ loading, error }}
                    id={id}
                    loadingLabel={words.text('page.loading')}
                    errorSentence={words.text(LIST_ERROR)}
                    notFoundSentence={words.text(NOT_FOUND)}
                    backLabel={words.text('page.back')}
                    listHref={LIST}
                    onBack={() => go(LIST)}
                    create={
                        <Editor
                            key="new"
                            row={null}
                            initial={blankForm(props.settings.granularity)}
                            links={createLinks(props.roster)}
                            currency={props.tenant.currency}
                            categories={props.categories}
                            granularity={props.settings.granularity}
                            clock={clock}
                            roster={roster}
                            busy={busy}
                            onSave={save}
                            onBack={() => go(LIST)}
                            danger={null}
                            copyLink={null}
                        />
                    }
                >
                    {(row) => (
                        <Editor
                            key={`${row.id}:${JSON.stringify(row)}:${JSON.stringify(props.roster)}`}
                            row={row}
                            initial={formOf(row)}
                            links={linkedMembers(props.roster, row.id)}
                            currency={row.currency}
                            categories={props.categories}
                            granularity={props.settings.granularity}
                            clock={clock}
                            roster={roster}
                            busy={busy}
                            onSave={save}
                            onBack={() => go(LIST)}
                            danger={<RowDanger row={row} />}
                            copyLink={copyLinkOf(props.links, row.id, props.permissions)}
                        />
                    )}
                </ListDetail>
            </div>
        </ServicesProvider>
    );
}

type EditorProps = {
    row: Service | null;
    initial: Form;
    links: readonly number[];
    currency: string;
    categories: readonly string[];
    granularity: number;
    clock: TenantClock;
    roster: Roster | null;
    busy: boolean;
    onSave: (form: Form, staged: readonly number[], row: Service | null) => void;
    onBack: () => void;
    danger: ReactNode;
    copyLink: ReactNode;
};

/**
 * The form's edits and the checklist's staged ticks, seeded from the row and the roster the
 * server answered and kept until the next answer replaces them: the page keys this on the row
 * and the roster, so a landed save rebuilds it from the server's row and a refused one keeps
 * every edit.
 */
function Editor({ row, initial, links, currency, categories, granularity, clock, roster, busy, onSave, onBack, danger, copyLink }: EditorProps) {
    const words = useServices();
    const [form, setForm] = useState<Form>(initial);
    const [staged, setStaged] = useState<number[]>([...links]);

    return (
        <ServiceForm
            form={form}
            onChange={setForm}
            creating={row === null}
            categories={categories}
            title={row === null ? words.text('page.new') : row.name}
            status={row === null ? null : row.status}
            currency={currency}
            granularity={granularity}
            clock={clock}
            staff={roster}
            staged={staged}
            onStage={setStaged}
            busy={busy}
            onSave={() => onSave(form, staged, row)}
            onBack={onBack}
            listHref={LIST}
            danger={danger}
            copyLink={copyLink}
        />
    );
}

/**
 * The danger zone of one persisted row, with the state its two buttons earn: the busy flag of
 * each, and the referenced conflict the delete guard answered, 9.2, which renders beside the
 * button that earned it and nowhere else. It is mounted under the row's Editor and keyed with
 * it, so leaving the row unmounts the notice and no other row, and not the create route, can
 * inherit it. remove() is the delete; a 409 whose code is referenced becomes the inline
 * conflict and nothing else, any other failure a toast. deactivate() is the remedy: it puts
 * the status alone and clears the conflict on success, the chip reading from the new props.
 */
function RowDanger({ row }: { row: Service }) {
    const toasts = useToasts();
    const shell = useShell();
    const words = useServices();
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
                onSuccess: () => setConflict(null),
                onFinish: () => {
                    setDeactivating(false);
                    owned.onFinish();
                },
            },
        );
    };

    return <DangerZone conflict={conflict} deleting={deleting} deactivating={deactivating} onDelete={remove} onDeactivate={deactivate} />;
}
