import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { MoveFields, MoveFooter, useMoveForm } from '@/bookings/MoveForm';
import { customerName, distinct, type Movable } from '@/bookings/model';
import { useBookings } from '@/bookings/words';
import { Tick } from '@/components/glyphs';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { Notice } from '@/components/ui/notice';
import { StatusChip } from '@/components/ui/status-chip';
import { formatInstant, type TenantClock } from '@/lib/format';
import { listOf } from '@/lib/names';
import { placed, type ShellKey, useShell } from '@/lib/strings';
import { type Failure, fieldsOf, owningFailure } from '@/lib/visits';

/*
 * A new closure over time that already holds bookings, as both closure forms meet it, the
 * Calendar's dialog and the Staff screen's card: one piece of logic and one look for the two.
 *
 * Add posts the closure with no answer for any booking. Where bookings fall inside it, the server
 * writes nothing and refuses with the list (bookings_inside): its sentence, the bookings soonest
 * first, and the answers this user may give for them. The form then shows the list in place of
 * its fields, each row its start on the tenant's clock, its customer, its services, its member
 * where the screen shows members, and its status, the list scrolling however long it runs; under
 * it, while cancelling is offered, that it cannot be undone, and, where a reason was typed, that
 * the email to each customer carries it. One button per answer offered, and Back, which returns
 * to the fields as typed and writes nothing.
 *
 * An answer posts the same body again with the answer and what was shown, each row's id and
 * status. Where the bookings inside changed meanwhile the server refuses once more with them as
 * they stand, and the list, its answers and its sentence are replaced. Any other refusal is the
 * owner's to say, as an add's is. A landed answer is a redirect back whose flash says what was
 * done; after Add and move them, the bookings shown open as a list to move, each row's Move the
 * usual Move form for that booking, a moved row ticked, Done closing it.
 *
 * A refusal of the answer itself, of the answer named or of what was shown with it, stays in the
 * list as the server's sentence. The plain add sends neither the answer nor what was shown, and
 * the body goes as JSON, so an empty list shown travels as an empty list.
 *
 * A row may carry its id and status alone, for a user who may not view bookings: it shows its
 * status and nothing to move. A service with no named member, on a business without staff or on
 * the business's own row, names nobody.
 */

/** The answers a list may offer, in the order its buttons stand. */
type InsideChoice = 'keep' | 'move' | 'cancel';

const CHOICES: readonly InsideChoice[] = ['keep', 'move', 'cancel'];

/** A booking a closure falls over: its id and status always, and what it is where the list says so. */
type InsideRow = {
    uuid: string;
    status: string;
    booking: Movable | null;
};

/** The list step: the server's sentence, the rows it lists, the answers it offers, and the sentence of an answer refused as sent, null while none is. */
type Asked = {
    detail: string;
    rows: InsideRow[];
    choices: InsideChoice[];
    refused: string | null;
};

/** The refusal that carries the list. */
const BOOKINGS_INSIDE = 'bookings_inside';

const CHOICE_KEYS: Record<InsideChoice, ShellKey> = {
    keep: 'shared.closure.keep',
    move: 'shared.closure.move',
    cancel: 'shared.closure.cancel',
};

/** A row's line, the customer and the services, and the one naming the members after them where the screen shows members. */
const ROW: ShellKey = 'shared.closure.row';

const ROW_STAFF: ShellKey = 'shared.closure.row_staff';

function text(value: unknown): string | null {
    return typeof value === 'string' ? value : null;
}

/** A listed booking as the screen can show and move it, or null where the row says no more than its id and status. */
function movableOf(row: Record<string, unknown>, uuid: string): Movable | null {
    if (!Array.isArray(row.items) || row.items.length === 0) {
        return null;
    }

    const items = row.items.map(fieldsOf).map((item) => ({
        service_name: text(item.service_name),
        staff_name: text(item.staff_name),
        start: text(item.start),
    }));
    const whole = items.flatMap((item) =>
        item.service_name === null || item.start === null
            ? []
            : [
                  {
                      service_name: item.service_name,
                      staff_name: item.staff_name,
                      start: item.start,
                  },
              ],
    );

    return whole.length === items.length ? { uuid, customer_name: text(row.customer_name), items: whole } : null;
}

/** The rows a refusal lists, each with an id and a status; anything else in it left out. */
function rowsOf(value: unknown): InsideRow[] {
    return (Array.isArray(value) ? value : []).map(fieldsOf).flatMap((row): InsideRow[] => {
        const uuid = text(row.uuid);
        const status = text(row.status);

        return uuid === null || status === null ? [] : [{ uuid, status, booking: movableOf(row, uuid) }];
    });
}

/** The list step a refusal opens, or null where the refusal is any other. */
function askedOf(failure: Failure, carried: Record<string, unknown>): Asked | null {
    if (failure.code !== BOOKINGS_INSIDE) {
        return null;
    }

    const offered = Array.isArray(carried.choices) ? carried.choices : [];

    return {
        detail: failure.sentence ?? '',
        rows: rowsOf(carried.bookings),
        choices: CHOICES.filter((choice) => offered.includes(choice)),
        refused: null,
    };
}

/** Whether a refusal names the answer itself, or what was shown with it, rather than a field of the form. */
function refusesTheAnswer(failure: Failure): boolean {
    return failure.field === 'choice' || (failure.field?.startsWith('seen') ?? false);
}

type Body = Record<string, string | null>;

/** What the step's owner reads and calls. pressed: the press in flight, an add or an answer. toMove: the list to move, open after a move landed. */
type ClosureAdd = {
    asked: Asked | null;
    pressed: InsideChoice | 'add' | null;
    toMove: InsideRow[] | null;
    add: (path: string, body: Body, onAdded: (toMove: boolean) => void) => void;
    choose: (choice: InsideChoice) => void;
    back: () => void;
    done: () => void;
};

/**
 * The add and its answers. onRefused hears every refusal but the list's; onAdded, passed with the
 * add, hears the closure landed, whichever press it was, and whether the list to move opened.
 */
export function useClosureAdd(onRefused: (failure: Failure) => void): ClosureAdd {
    const shell = useShell();
    const [asked, setAsked] = useState<Asked | null>(null);
    const [pressed, setPressed] = useState<InsideChoice | 'add' | null>(null);
    const [toMove, setToMove] = useState<InsideRow[] | null>(null);
    const sent = useRef<{
        path: string;
        body: Body;
        onAdded: (toMove: boolean) => void;
    } | null>(null);

    const post = (choice: InsideChoice | null, shown: InsideRow[]) => {
        const add = sent.current;

        if (add === null || pressed !== null) {
            return;
        }

        const owned = owningFailure((failure, carried) => {
            const next = askedOf(failure, carried);

            if (next !== null) {
                setAsked(next);
            } else if (choice !== null && refusesTheAnswer(failure)) {
                setAsked((current) => (current === null ? current : { ...current, refused: failure.sentence ?? '' }));
            } else {
                onRefused(failure);
            }
        }, shell);
        const body =
            choice === null
                ? add.body
                : {
                      ...add.body,
                      choice,
                      seen: shown.map(({ uuid, status }) => ({ uuid, status })),
                  };

        router.post(add.path, body, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                setPressed(choice ?? 'add');
                setAsked((current) => (current === null || current.refused === null ? current : { ...current, refused: null }));
                owned.onStart();
            },
            onSuccess: () => {
                setAsked(null);

                if (choice === 'move') {
                    setToMove(shown);
                }

                add.onAdded(choice === 'move');
            },
            onFinish: () => {
                setPressed(null);
                owned.onFinish();
            },
        });
    };

    return {
        asked,
        pressed,
        toMove,
        add: (path, body, onAdded) => {
            sent.current = { path, body, onAdded };
            post(null, []);
        },
        choose: (choice) => {
            if (asked !== null && asked.choices.includes(choice)) {
                post(choice, asked.rows);
            }
        },
        back: () => setAsked(null),
        done: () => setToMove(null),
    };
}

/**
 * A row's facts: its start on the tenant's clock, its status, and one line of its customer, its
 * services and its members where members show, each placed where the line's key puts it, so the
 * order and what stands between them are the language's.
 */
function RowFacts({ row, staffShown, clock }: { row: InsideRow; staffShown: boolean; clock: TenantClock }) {
    const words = useBookings();
    const shell = useShell();
    const { booking } = row;
    const staff = booking === null || !staffShown ? [] : distinct(booking.items.map((item) => item.staff_name));

    return (
        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
            <span className="flex flex-wrap items-center gap-2">
                {booking !== null && <span className="admin-inside__when font-medium">{formatInstant(booking.items[0]?.start ?? '', clock)}</span>}
                <StatusChip status={row.status} className="admin-inside__status" />
            </span>
            {booking !== null && (
                <span className="admin-inside__what text-muted-foreground">
                    {placed(shell.text(staff.length > 0 ? ROW_STAFF : ROW), {
                        customer: <span className="admin-inside__customer font-medium text-foreground">{customerName(booking, words)}</span>,
                        services: <span className="admin-inside__services">{listOf(distinct(booking.items.map((item) => item.service_name)), words.language)}</span>,
                        staff: <span className="admin-inside__staff">{listOf(staff, words.language)}</span>,
                    })}
                </span>
            )}
        </span>
    );
}

type ListProps = {
    asked: Asked;
    staffShown: boolean;
    clock: TenantClock;
    reasonTyped: boolean;
};

/** The list step's body: the server's sentence, the rows, scrolling however many, and the lines under them. */
export function InsideList({ asked, staffShown, clock, reasonTyped }: ListProps) {
    const shell = useShell();
    const cancels = asked.choices.includes('cancel');

    return (
        <div className="admin-inside flex flex-col gap-3">
            <p className="admin-inside__detail font-medium" role="alert">
                {asked.detail}
            </p>
            <ul className="admin-inside__list flex max-h-72 flex-col overflow-y-auto rounded-inner ring-[1.5px] ring-border">
                {asked.rows.map((row) => (
                    <li key={row.uuid} className="admin-inside__row flex items-start gap-3 border-t px-4 py-2.5 text-body-sm tabular-nums first:border-t-0">
                        <RowFacts row={row} staffShown={staffShown} clock={clock} />
                    </li>
                ))}
            </ul>
            {cancels && <p className="admin-inside__final text-sm text-muted-foreground">{shell.text('shared.closure.final')}</p>}
            {cancels && reasonTyped && <p className="admin-inside__reason-sent text-sm text-muted-foreground">{shell.text('shared.closure.reason_sent')}</p>}
            {asked.refused !== null && (
                <Notice kind="error" className="admin-inside__refused">
                    {asked.refused}
                </Notice>
            )}
        </div>
    );
}

/**
 * The list step's buttons: Back, then one per answer offered, the press in flight busy and the
 * rest held. held: the owner has another write of its own in flight, a closure being removed from
 * the card the list stands in, and every button waits for it, since a second visit would cut the
 * first one short and lose what it had to say. An answer names what it does and how many bookings
 * it does it to, which in a language of longer words is wider than a phone's dialog, so its
 * label may wrap there.
 */
export function InsideChoices({ adding, held = false }: { adding: ClosureAdd; held?: boolean }) {
    const shell = useShell();
    const { asked, pressed } = adding;

    if (asked === null) {
        return null;
    }

    const count = asked.rows.length;

    return (
        <div className="admin-inside__choices flex flex-wrap justify-end gap-2">
            <Button className="admin-inside__back" disabled={held || pressed !== null} onClick={adding.back}>
                {shell.text('shared.closure.back')}
            </Button>
            {asked.choices.map((choice) => (
                <Button
                    key={choice}
                    wrap
                    variant={choice === 'cancel' ? 'primary' : 'secondary'}
                    destructive={choice === 'cancel'}
                    className={`admin-inside__${choice}`}
                    disabled={held || (pressed !== null && pressed !== choice)}
                    busy={pressed === choice}
                    busyLabel={shell.text('shared.closure.adding')}
                    onClick={() => adding.choose(choice)}
                >
                    {shell.choice(CHOICE_KEYS[choice], count)}
                </Button>
            ))}
        </div>
    );
}

type ToMoveProps = {
    rows: readonly InsideRow[];
    staffShown: boolean;
    clock: TenantClock;
    weekStart: number;
    onDone: () => void;
};

/**
 * The bookings to move, open after Add and move them landed: each row with Move, which turns the
 * dialog into the usual Move form for that booking (its closed-time warning included) and back
 * into the list once the move lands or Back is pressed; a moved row shows a tick and Moved in
 * place of its button. Done, or closing the dialog, leaves the rest where they are.
 */
export function BookingsToMove({ rows, staffShown, clock, weekStart, onDone }: ToMoveProps) {
    const shell = useShell();
    const [opened, setOpened] = useState<{ row: InsideRow } | null>(null);
    const [moved, setMoved] = useState<ReadonlySet<string>>(new Set());
    const form = useMoveForm({
        booking: opened?.row.booking ?? null,
        clock,
        onMoved: (uuid) => setMoved((was) => new Set([...was, uuid])),
    });

    useEffect(() => {
        if (opened !== null) {
            form.begin();
        }
    }, [opened]);

    const booking = form.moving ? (opened?.row.booking ?? null) : null;
    const footer =
        booking !== null ? (
            <MoveFooter form={form} />
        ) : (
            <Button variant="primary" className="admin-to-move__done" onClick={onDone}>
                {shell.text('shared.closure.done')}
            </Button>
        );

    return (
        <Modal open title={shell.text('shared.closure.to_move')} onClose={booking !== null ? form.back : onDone} footer={<div className="flex flex-wrap justify-end gap-2">{footer}</div>} className="admin-to-move max-w-2xl">
            {form.failure !== null && <Notice kind="error">{form.failure}</Notice>}
            {booking !== null ? (
                <MoveFields form={form} booking={booking} staffShown={staffShown} clock={clock} weekStart={weekStart} />
            ) : (
                <ul className="admin-to-move__list flex flex-col rounded-inner ring-[1.5px] ring-border">
                    {rows.map((row) => (
                        <li key={row.uuid} className="admin-to-move__row flex items-center gap-3 border-t px-4 py-2.5 text-body-sm tabular-nums first:border-t-0" data-uuid={row.uuid}>
                            <RowFacts row={row} staffShown={staffShown} clock={clock} />
                            {moved.has(row.uuid) ? (
                                <span className="admin-to-move__moved inline-flex items-center gap-1 font-medium text-positive-ink">
                                    <Tick />
                                    {shell.text('shared.closure.moved')}
                                </span>
                            ) : (
                                row.booking !== null && (
                                    <Button className="admin-to-move__move" onClick={() => setOpened({ row })}>
                                        {shell.text('shared.closure.move_one')}
                                    </Button>
                                )
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Modal>
    );
}
