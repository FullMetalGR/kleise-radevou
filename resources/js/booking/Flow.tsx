import { useQuery } from '@tanstack/react-query';
import { useEffect, useId, useReducer, useRef, useState, type Dispatch, type ReactNode, type RefObject } from 'react';
import type { Api } from './api';
import type { MountOptions } from './attributes';
import { availabilityQuery, chainReady, itemsOf, windowIndexOf, windowOf } from './availability';
import type { Booking } from './booking';
import { catalogQuery, type Catalog } from './catalog';
import type { BootConfig } from './config';
import { useConfirm, type Confirm } from './confirm';
import { BUSY_LABEL, DetailsStep } from './DetailsStep';
import { DoneStep, headingFor } from './DoneStep';
import { CONTINUE_LABEL, Estimate, PICK_A_TIME_LABEL, type Proceed } from './Estimate';
import { afterService, anySegmentListsStaff, initialFlowState, onStrip, preselectionVoid, reduce, restartOffered, underReview, type FlowEvent, type FlowState, type Step, type StripName } from './flow';
import { Frame } from './Frame';
import { holdBody, useHold } from './hold';
import { useRecovery } from './recovery';
import { useHoldRelease, type HoldRelease } from './release';
import { ReviewStep } from './ReviewStep';
import { ServiceStep, useRemoval } from './ServiceStep';
import { StaffStep } from './StaffStep';
import { Strip } from './Strip';
import { todayIn } from './time';
import { WhenStep } from './WhenStep';
import { useWords, type LineKey, type Words } from './words';

/*
 * The flow: one component owning all flow state, component-local and nothing else (1.7). Nothing
 * survives a remount, a reload or navigation. The current step is echoed on the flow container
 * beside the mode and the shell state. Focus follows the customer (7.4): a customer-initiated
 * transition arms a flag an effect consumes to focus the container; the auto-skip arms nothing;
 * the initial mount steals nothing. Every step of 1.1 is built.
 *
 * The frame carries the step's title, the crumb above the step with the one Back, and beside the
 * step from service to details the summary card holding the one Continue, wired here per step: on
 * service it moves on from the first segment, on staff once the catalog has answered, on when it
 * is disabled and says to pick a time, since a pick is the transition, and on details it is the
 * form's submit button through the form attribute. Review and done have no card and no crumb: the
 * estimate is never the number the customer confirms. The removal of a segment, by a pressed card
 * or by the card's remove control, is one rule, held here because both controls feed it.
 *
 * The preselection is judged once, on the first catalog answer (1.6): a mount naming a service
 * no row has is void, and the flow becomes a bare mount's with nothing said. The judgement is a
 * ref rather than state because it is asked and answered once and never rendered, and a later
 * refetch that drops a service must find it already answered. The auto-skip waits for a chain
 * the catalog can name, so the effect that runs beside the void on the same answer cannot move a
 * dead chain on to when, and a refetch that drops a live service cannot move a customer either.
 *
 * The availability query lives here rather than on the when step, enabled from the moment the
 * chain is non-empty and every item names a service the catalog answered (3.1), and stays
 * subscribed on the later steps: that is what makes the hold 409's invalidation refetch an
 * active query immediately, so the customer lands back on a refreshed list rather than on a
 * stale one. The window is seven tenant-local days, the first beginning on the tenant's
 * today, which is the current instant formatted in the config's time zone and never the
 * browser's date; the browser clock is read here for that instant and nowhere else.
 *
 * The hold is the flow's, because its answer moves the flow: a pending booking to review, any
 * other status straight to done, a 409 back to when. The confirm is the flow's for the same
 * reason: its answer is the settled booking the done step reads. The synchronous latch is the
 * guard that a second submit or a second confirm sends nothing: the mutation observer notifies
 * through a deferred scheduler, so the rendered disabled attribute reaches the DOM one macrotask
 * late and is the visible affordance only.
 *
 * Every refused confirm, whatever its status, goes through the one recovery of 4.4 before
 * anything is shown. The recovering flag goes up with the confirm latch still held, so no second
 * confirm starts while a booking that may already be settled is looked up, and the restart
 * control renders from that moment, countdown or none. The re-read is the recovery module's,
 * ticketed; only a current answer lands, and it lands here: the flag comes down and the latch is
 * freed, then a settled booking is the outcome, landing on done as the answer to the customer's
 * press and so with focus on the container, the refusal shown nowhere, while anything else, a
 * failed re-read included, renders the refusal by 4.1's tone. A 410 raises the expiry flag the
 * moment it arrives, before the re-read goes, so the countdown stops and says nothing more while
 * the booking is looked up (4.2). The confirm hook underneath does not change.
 *
 * The countdown's zero raises the expiry flag a 410 raises (5.3, 4.2). "Pick another time"
 * (1.3, 1.5, 5.4) is the way out: it releases the hold only if the hold is still live by its own
 * deadline, the split being on the deadline and never on which control was pressed; it drops
 * the manage token, since the booking it managed is gone and the next hold brings its own; it
 * abandons any recovery still out, so a re-read answering late lands nowhere; it resets the
 * confirm latch explicitly (4.5); and it lands on when as a customer-caused move. The
 * list the customer lands on is the availability query that never unsubscribed, so nothing is
 * invalidated.
 *
 * The automatic release listens for the page hiding and for the tab turning hidden, on the
 * document the flow is mounted in, and the listeners leave with the flow. It releases the hold
 * the review step is showing, under the one guard "Pick another time" releases under, so a hold
 * is released once by whichever comes first; holds are marked by their identifier, and two flows
 * on one page each release their own. It never fires on done: a confirmed booking is not a hold
 * and a lodged approval request must survive the tab closing, so the confirm's answer takes the
 * hold out of reach before the step changes. It never fires once the hold is handed off: with a
 * checkout_url the review's press takes the confirm latch and keeps it, marks the hold handed
 * off, and only then navigates, because that navigation is itself a page hide. The press also
 * sets the notice saying why the button stays disabled, so a page the browser brings back from
 * its back-forward cache shows that sentence and "Pick another time" beside the disabled button
 * rather than a dead end. The hand-off binds the automatic release alone: restarting from there
 * releases a hold still live by its deadline, as any restart does, so the customer's own hold
 * does not block the slot they go back to re-pick.
 */

interface FlowProps {
    readonly config: BootConfig;
    readonly options: MountOptions;
    readonly api: Api;
    /** The browser the flow reads the time from and leaves through; the real one by default. */
    readonly browser?: Browser;
}

/**
 * The two things the flow takes from the browser that a component test must be able to replace.
 */
interface Browser {
    /**
     * The browser clock: read once to establish the current instant, by the review step's
     * countdown, and by both releases for whether the hold is still live.
     */
    readonly now: () => number;
    /**
     * Where the hand-off sends the customer: the location, since a real navigation is not
     * something a component test can follow.
     */
    readonly navigate: (url: string) => void;
}

const THE_BROWSER: Browser = {
    now: Date.now,
    navigate: (url) => {
        window.location.assign(url);
    },
};

export function Flow({ config, options, api, browser = THE_BROWSER }: FlowProps): ReactNode {
    const { now, navigate } = browser;
    const words = useWords();
    const formId = useId();
    // The draft is seeded from the hosted page's prefill once, here, and never again (2.4, 1.5).
    const [state, dispatch] = useReducer(reduce, options, (mounted: MountOptions) => initialFlowState(mounted, config.customer ?? null));
    const [today] = useState(() => todayIn(config.timezone, now()));
    const { container, armed } = useFocusFollows(state);
    const catalog = useQuery(catalogQuery(api));
    const [token, setManageToken] = useManageToken();

    // The release, by "Pick another time" or by the page hiding, once per hold (5.4).
    const { releases, reviewing } = useAutomaticRelease(api, now, container, token, state);

    // The hold's three answers. A pending booking is one to review and any other status is
    // the outcome itself; a 409 clears the slot and goes back to when, which is a
    // customer-caused move and so lands focus; anything else is a sentence beside the form.
    const hold = useHold(api, {
        onHeld: (held) => {
            setManageToken(held.manageToken);
            armed.current = true;
            dispatch({ type: 'held', booking: held.booking });
        },
        onConflict: (notice) => {
            armed.current = true;
            dispatch({ type: 'conflict', notice });
        },
        onRefused: (notice) => dispatch({ type: 'refused', notice }),
    });

    // The confirm, 2.5 and 4.1: a settled booking is the outcome the done step reads, taken out
    // of the page-hide release's reach before the step changes. A refusal is the recovery's
    // (4.4), which holds the latch across its re-read and frees it only when a current answer
    // lands; a settled re-read is the same outcome, and anything else is the server's sentence
    // beside the summary. A 410 raises the expiry flag as the recovery starts, not as it ends.
    function landed(booking: Booking): void {
        reviewing.current = null;
        armed.current = true;
        dispatch({ type: 'confirmed', booking });
    }

    const recovery = useRecovery(api);
    const confirm = useConfirm(api, {
        onConfirmed: landed,
        onRefused: (refusal) => {
            dispatch({ type: 'recover', expired: refusal.expired });
            recovery.recover(state.booking, token.current, {
                settled: (booking) => {
                    confirm.release();
                    landed(booking);
                },
                stands: () => {
                    confirm.release();
                    dispatch({ type: 'confirm-refused', ...refusal });
                },
            });
        },
    });
    const { step, segments } = state;

    // The review button is busy while a confirm is out and while its refusal is being recovered.
    const confirming = confirm.busy || state.recovering;

    const { from, to } = windowOf(today, state.window);
    const availability = useQuery({ ...availabilityQuery(api, itemsOf(segments), from, to), enabled: chainReady(segments, catalog.data) });

    // A customer-initiated transition: arm, then move. useFocusFollows lands the focus.
    function go(next: Step): void {
        armed.current = true;
        dispatch({ type: 'go', step: next });
    }

    function pick(start: string): void {
        armed.current = true;
        dispatch({ type: 'pick', start });
    }

    // Next available date, 2.3: the window to the week holding the day and the day selected, in
    // one move the customer made, so focus lands on the container. A day of this week needs no
    // request; a later one is the next window's read.
    function jump(day: string): void {
        armed.current = true;
        dispatch({ type: 'jump', index: windowIndexOf(today, day), day });
    }

    // Back is disabled while a hold flies and guarded by the same latch besides (1.4), so an
    // answer can never land on a step the customer has already left. The crumb's back control
    // and its finished steps both go through here, to a target Back's table reaches.
    function goBack(target: Step): void {
        if (!hold.flying()) {
            go(target);
        }
    }

    // The details submit: the one place a hold is created. The hook holds the latch; there
    // is nothing to send without a chosen start.
    function submit(): void {
        if (state.chosenStart !== null) {
            hold.send(holdBody(state.draft, itemsOf(segments), state.chosenStart, options.channel));
        }
    }

    // "Pick another time", 1.3 and 5.4. The release fires from this handler with the ref's
    // token, best effort and only while the hold's own deadline is ahead and this page has not
    // released it already, handed off or not; the token is dropped straight after, so a second
    // press in the same task finds nothing to release. A recovery still out is abandoned before
    // the latch is reset, so its answer can neither land nor free the next hold's latch.
    function restart(): void {
        releases.release(state.booking, token.current);
        token.current = null;
        setManageToken(null);
        recovery.abandon();
        confirm.reset();
        armed.current = true;
        dispatch({ type: 'restart' });
    }

    // The customer's last press, 2.5: the booking the server answered and the credential it
    // answered with, which is the ref and never a render's copy.
    function settle(): void {
        if (state.booking !== null && token.current !== null) {
            confirm.send(state.booking.uuid, token.current);
        }
    }

    usePreselectionCheck(options, catalog.data, dispatch);

    const removal = useRemoval(container, catalog.data, segments, step === 'service', (index) => dispatch({ type: 'remove', index }));

    // The auto-skip of 2.2: a machine transition, so it arms nothing and moves no focus.
    useEffect(() => {
        if (step === 'staff' && chainReady(segments, catalog.data) && !anySegmentListsStaff(segments, catalog.data)) {
            dispatch({ type: 'go', step: 'when' });
        }
    }, [step, catalog.data, segments]);

    const catalogState = { data: catalog.data, error: catalog.error, isPending: catalog.isPending };

    /**
     * The one step the flow is on. A step that needs the held booking renders nothing without
     * one, which no answer can reach: only a hold that answered puts the flow there.
     */
    function content(): ReactNode {
        switch (step) {
            case 'service':
                return (
                    <ServiceStep
                        catalog={catalogState}
                        segments={segments}
                        announcement={removal.announcement}
                        onAppend={(service) => dispatch({ type: 'append', service })}
                        onRemove={(index) => removal.remove(index, false)}
                    />
                );
            case 'staff':
                return (
                    <StaffStep catalog={catalogState} segments={segments} onChoose={(index, staffId) => dispatch({ type: 'choose', index, staffId })} />
                );
            case 'when':
                return (
                    <WhenStep
                        timezone={config.timezone}
                        availability={{ data: availability.data, error: availability.error, isFetching: availability.isFetching }}
                        window={state.window}
                        selectedDay={state.selectedDay}
                        chosenStart={state.chosenStart}
                        conflict={state.conflict}
                        onWindow={(index) => dispatch({ type: 'window', index })}
                        onSelectDay={(day) => dispatch({ type: 'select-day', day })}
                        onPick={pick}
                        onNextAvailable={jump}
                    />
                );
            case 'details':
                return (
                    <DetailsStep
                        formId={formId}
                        draft={state.draft}
                        notice={state.detailsNotice}
                        onChange={(field, value) => dispatch({ type: 'draft', field, value })}
                        onSubmit={submit}
                    />
                );
            case 'review':
                return state.booking === null ? null : (
                    <ReviewStep
                        booking={state.booking}
                        timezone={config.timezone}
                        notice={state.reviewNotice}
                        busy={confirming}
                        expired={state.expired}
                        restartable={restartOffered(state)}
                        importantInfo={config.importantInfo}
                        now={now}
                        onConfirm={settle}
                        onPay={() => handOff(state.booking, confirm, releases, () => dispatch({ type: 'handed-off' }), navigate)}
                        onExpire={() => dispatch({ type: 'expire' })}
                        onRestart={restart}
                    />
                );
            case 'done':
                return state.booking === null ? null : <DoneStep status={state.booking.status} atVenue={state.booking.paysAtVenue} />;
        }
    }

    const carded = onStrip(step) ? step : null;

    return (
        <Frame mode={options.mode} state="ready" step={step} ref={container}>
            <Strip state={state} catalog={catalog.data} busy={hold.busy} onGo={goBack} />
            <div className={carded === null ? 'kr-booking__layout kr-booking__layout--single' : 'kr-booking__layout'}>
                <div className="kr-booking__main">
                    <h2 className="kr-booking__title">{titleOf(step, state.booking, words)}</h2>
                    {content()}
                </div>
                {carded !== null && (
                    <Estimate
                        tenantName={config.tenantName}
                        catalog={catalog.data}
                        segments={segments}
                        step={carded}
                        timezone={config.timezone}
                        selectedDay={state.selectedDay}
                        chosenStart={state.chosenStart}
                        proceed={proceedOf(carded, { segments, catalog: catalog.data, waiting: catalog.isPending, busy: hold.busy, formId, go })}
                        onRemove={carded === 'service' ? (index) => removal.remove(index, true) : undefined}
                    />
                )}
            </div>
        </Frame>
    );
}

/** What the summary card's Continue is wired from: the chain, the catalog, the hold, the form. */
interface ProceedContext {
    readonly segments: FlowState['segments'];
    readonly catalog: Catalog | undefined;
    /** The catalog is still on its way. */
    readonly waiting: boolean;
    /** A hold is in flight. */
    readonly busy: boolean;
    readonly formId: string;
    readonly go: (next: Step) => void;
}

/**
 * The summary card's Continue on the step the flow is on (2.3): on service from the first
 * segment, on staff once the catalog is no longer on its way, on when never, since a pick is the
 * transition, and on details the form's submit, busy while the hold flies.
 */
function proceedOf(on: StripName, { segments, catalog, waiting, busy, formId, go }: ProceedContext): Proceed {
    switch (on) {
        case 'service':
            return { label: CONTINUE_LABEL, disabled: segments.length === 0, onPress: () => go(afterService(segments, catalog)) };
        case 'staff':
            return { label: CONTINUE_LABEL, disabled: waiting, onPress: () => go('when') };
        case 'when':
            return { label: PICK_A_TIME_LABEL, disabled: true };
        case 'details':
            return { label: busy ? BUSY_LABEL : CONTINUE_LABEL, disabled: busy, form: formId };
    }
}

/** The frame's title on each step before done, whose heading is its returned status's. */
export const STEP_TITLES: Readonly<Record<Exclude<Step, 'done'>, LineKey>> = {
    service: 'titles.service',
    staff: 'titles.staff',
    when: 'titles.when',
    details: 'titles.details',
    review: 'titles.review',
};

/**
 * The frame's title: the step's own, and on done the heading the returned status chooses (2.6),
 * since done is reached only with an answer in hand.
 */
function titleOf(step: Step, booking: Booking | null, words: Words): string {
    return words.text(step === 'done' ? headingFor(booking?.status ?? '') : STEP_TITLES[step]);
}

/**
 * Focus follows the customer, 7.4: a customer-initiated transition arms the flag and the effect
 * lands focus on the flow container once the step has moved, or, for Next available date, once
 * the window and the selected day have. A machine transition arms nothing, and the initial mount
 * steals nothing.
 */
function useFocusFollows({ step, window, selectedDay }: Pick<FlowState, 'step' | 'window' | 'selectedDay'>): { readonly container: RefObject<HTMLDivElement | null>; readonly armed: RefObject<boolean> } {
    const container = useRef<HTMLDivElement>(null);
    const armed = useRef(false);

    useEffect(() => {
        if (armed.current) {
            armed.current = false;
            container.current?.focus();
        }
    }, [step, window, selectedDay]);

    return { container, armed };
}

/**
 * The manage token, 5.5: component state and one ref, and nowhere else. No storage, no cookie, no
 * URL this code builds, no DOM attribute, no log line. The confirm, the recovery's re-read and
 * the release read the ref, because they fire from handlers outside a render; the state is what
 * a render would need.
 */
function useManageToken(): readonly [RefObject<string | null>, (token: string | null) => void] {
    const [manageToken, setManageToken] = useState<string | null>(null);

    return [useLatest(manageToken), setManageToken];
}

/**
 * The judge-once check of 1.6, asked of the first catalog answer and never again: a mount naming
 * a service no row has is void. A machine transition, so it arms nothing and moves no focus, and
 * the customer is told nothing.
 */
function usePreselectionCheck(options: MountOptions, catalog: Catalog | undefined, dispatch: Dispatch<FlowEvent>): void {
    const judged = useRef(false);

    useEffect(() => {
        if (catalog !== undefined && !judged.current) {
            judged.current = true;

            if (preselectionVoid(options, catalog)) {
                dispatch({ type: 'void', staffId: options.staffId });
            }
        }
    }, [catalog, options, dispatch]);
}

/**
 * The payment branch's press, 2.5 and 5.4: take the latch and keep it, mark the hold handed off,
 * say why the button stays disabled, and only then navigate, because the navigation is itself a
 * page hide. No confirm is sent, and the url is followed and never shown. A press the latch
 * refuses does nothing at all.
 */
function handOff(held: Booking | null, confirm: Confirm, releases: HoldRelease, said: () => void, navigate: (url: string) => void): void {
    if (held?.checkoutUrl === undefined || !confirm.latch()) {
        return;
    }

    releases.handOff(held);
    said();
    navigate(held.checkoutUrl);
}

/**
 * The automatic release and the one-per-hold release it shares with "Pick another time". The hold
 * it would release is the one the review step is showing, kept in a ref for the listener, which
 * fires outside a render: its identifier and deadline and nothing that could carry the manage
 * token, since the token's one ref is the flow's; null on every other step, and the flow's to
 * null sooner where the confirm's answer lands, so nothing past review is ever released.
 */
function useAutomaticRelease(
    api: Api,
    now: () => number,
    container: RefObject<HTMLElement | null>,
    token: RefObject<string | null>,
    state: Pick<FlowState, 'step' | 'booking'>,
): { readonly releases: HoldRelease; readonly reviewing: RefObject<ReturnType<typeof underReview>> } {
    const releases = useHoldRelease(api, now);
    const reviewing = useLatest(underReview(state));

    useWhenHidden(container, () => releases.releaseOnHide(reviewing.current, token.current));

    return { releases, reviewing };
}

/**
 * A ref holding the latest value a render computed, for a listener that fires outside one. The
 * ref is the caller's to clear sooner, where a render would land too late.
 */
function useLatest<T>(value: T): RefObject<T> {
    const latest = useRef(value);

    useEffect(() => {
        latest.current = value;
    }, [value]);

    return latest;
}

/**
 * Calls back when the page hides or the tab turns hidden, the two moments 5.4 releases on,
 * listening on the document the container is mounted in and leaving with the component. The
 * latest callback is the one called, so the listeners are added once and not on every render.
 */
function useWhenHidden(container: RefObject<HTMLElement | null>, hidden: () => void): void {
    const latest = useLatest(hidden);

    useEffect(() => {
        const doc = container.current?.ownerDocument;
        const view = doc?.defaultView;

        if (doc === undefined || view === null || view === undefined) {
            return undefined;
        }

        const hide = (): void => latest.current();
        const turned = (): void => {
            if (doc.visibilityState === 'hidden') {
                latest.current();
            }
        };

        view.addEventListener('pagehide', hide);
        doc.addEventListener('visibilitychange', turned);

        return () => {
            view.removeEventListener('pagehide', hide);
            doc.removeEventListener('visibilitychange', turned);
        };
    }, [container, latest]);
}
