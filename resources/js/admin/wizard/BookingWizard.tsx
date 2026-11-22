import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Tick } from '@/components/glyphs';
import { Card } from '@/components/ui/card';
import { CHECK_BOX } from '@/components/ui/check-field';
import type { WizardKey, WizardWords } from '@/customers/words';
import { DayCalendar } from '@/components/ui/day-calendar';
import { Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { Notice } from '@/components/ui/notice';
import { Select } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, type TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { type Failure, owningFailure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';
import { useAvailability } from './availability';
import { addSegment, advance, ANY_STAFF, availabilityQuery, bodyOf, type Catalog, chooseDay, chooseService, chooseSlot, chooseStaff, courtesy, editCustomer, endOf, gateOpen, groupsOf, initial, NO_TIMES, performersOf, periodKey, type Prefill, removeSegment, reopen, serviceLines, staffChoiceReal, type State, type Step, STEPS, stripValues, timeLabel, toggleSameStaff } from './model';

/*
 * The manual booking wizard: a wide modal titled New booking, three steps long, opened over
 * whatever screen is showing. The state is model.ts's and every press is one of its transitions;
 * this file renders the strip, the three panels and the footer, asks the one JSON endpoint for
 * the day's times, and posts the create.
 *
 * Nothing is optimistic. Create shows a busy state; success is the server's redirect back with
 * its flash, on which the wizard closes and the page's props arrive re-read, so the list, the
 * detail and the calendar invalidate through the reload. A refusal is the envelope answered as
 * itself: its sentence becomes an error toast that stays until dismissed, a conflict naming the
 * segment, and the wizard stays open with every answer intact, the chosen slot included, 4.4
 * and 9.3.
 *
 * Below the admin's breakpoint the modal fills the viewport, the footer pinned and the body the
 * one scroller. The two-column panels of the second and third steps go by the body's own width,
 * not the window's: one column until the body is 36rem wide, two from there.
 *
 * Its words are the wizard group of lang/{locale}/admin/customers.php, which the shared props
 * hand beside the catalog and the opening passes down; the times' periods stay identifiers,
 * displayed through their keys, and the services, the members and the customer are the tenant's
 * and the customer's own text, never translated.
 */

export const CREATE_URL = '/admin/bookings';

const CREATE_FAILED = 'create_failed' satisfies WizardKey;

type Props = {
    prefill: Prefill;
    catalog: Catalog;
    clock: TenantClock;
    granularity: number;
    weekStart: number;
    entitlements: readonly string[];
    today: string;
    onClose: () => void;
    words: WizardWords;
};

/** The conflict's sentence with the failing segment named, where the envelope named one. */
function refusalSentence(failure: Failure, state: State, catalog: Catalog, words: WizardWords): string {
    const sentence = failure.sentence ?? words.text(CREATE_FAILED);
    const segment = failure.segment;

    if (segment === null) {
        return sentence;
    }

    const service = catalog.services.find((each) => each.id === state.segments[segment]?.service);

    const n = String(segment + 1);

    return service === undefined ? words.text('refused_segment', { sentence, n }) : words.text('refused_segment_service', { sentence, n, service: service.name });
}

export function BookingWizard({ prefill, catalog, clock, granularity, weekStart, entitlements, today, onClose, words }: Props) {
    const [state, setState] = useState<State>(() => courtesy(initial(today, prefill), catalog));
    const [creating, setCreating] = useState(false);
    const toasts = useToasts();
    const shell = useShell();
    const times = useAvailability(availabilityQuery(state), state.day, clock.timezone, words);
    const staffReal = staffChoiceReal(entitlements, catalog, state);

    useEffect(() => {
        setState((current) => courtesy(current, catalog));
    }, [catalog]);

    const create = () => {
        if (creating || !gateOpen(state)) {
            return;
        }

        const owned = owningFailure((failure) => toasts.push('error', refusalSentence(failure, state, catalog, words)), shell);

        router.post(CREATE_URL, bodyOf(state), {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                setCreating(true);
                owned.onStart();
            },
            onSuccess: onClose,
            onFinish: () => {
                setCreating(false);
                owned.onFinish();
            },
        });
    };

    const values = stripValues(state, catalog, clock, words);

    return (
        <Modal
            open
            title={words.text('title')}
            variant="filling"
            className="admin-wizard max-w-[62.5rem]"
            onClose={onClose}
            busy={creating}
            footer={
                <>
                    {state.step > 0 && (
                        <Button className="admin-wizard-back" disabled={creating} onClick={() => setState(reopen(state, (state.step - 1) as Step))}>
                            {words.text('back')}
                        </Button>
                    )}
                    <Button className="admin-wizard-cancel" disabled={creating} onClick={onClose}>
                        {words.text('cancel')}
                    </Button>
                    {state.step < 2 ? (
                        <Button variant="primary" className="admin-wizard-continue" disabled={!gateOpen(state)} onClick={() => setState(advance(state))}>
                            {words.text('continue')}
                        </Button>
                    ) : (
                        <Button variant="primary" className="admin-wizard-create" disabled={!gateOpen(state)} busy={creating} busyLabel={words.text('creating')} onClick={create}>
                            {words.text('create')}
                        </Button>
                    )}
                </>
            }
        >
            <div className={BODY}>
                <ol className="admin-wizard-strip mb-5 grid grid-cols-3 gap-2.5 max-desktop:flex max-desktop:gap-2">
                    {STEPS.map((name, at) => (
                        <li key={name} className={cn('min-w-0', at === state.step ? 'max-desktop:flex-1' : 'max-desktop:shrink-0')}>
                            <StripStep at={at} name={name} value={values[at]} step={state.step} day={state.day} words={words} onReopen={() => setState(reopen(state, at as Step))} />
                        </li>
                    ))}
                </ol>
                {state.step === 0 && <Services state={state} catalog={catalog} staffReal={staffReal} onChange={setState} words={words} />}
                {state.step === 1 && (
                    <div className="admin-wizard-when grid grid-cols-1 gap-6 @xl:grid-cols-2">
                        <DayCalendar className="admin-wizard-grid" selected={state.day} weekStart={weekStart} today={today} onChange={(day) => setState(chooseDay(state, day))} />
                        <div className="admin-wizard-times flex min-w-0 flex-col gap-3">
                            <p className="admin-wizard-zone text-small text-muted-foreground">{words.text('zone', { zone: clock.timezone })}</p>
                            {times.kind === 'loading' && (
                                <p className="admin-wizard-loading text-sm text-muted-foreground" role="status">
                                    <Spinner size="inline" className="mr-2" />
                                    {words.text('loading')}
                                </p>
                            )}
                            {times.kind === 'error' && <Notice kind="error">{times.sentence}</Notice>}
                            {times.kind === 'ready' && times.starts.length === 0 && <Notice kind="info">{words.text(NO_TIMES)}</Notice>}
                            {times.kind === 'ready' &&
                                groupsOf(times.starts, clock).map((group) => (
                                    <section key={group.name} className="admin-wizard-group">
                                        <h3 className="admin-wizard-group__title mb-2 text-label">
                                            {words.text(periodKey(group.name))} ({group.slots.length})
                                        </h3>
                                        <div className="grid grid-cols-3 gap-2 @xl:grid-cols-[repeat(auto-fill,minmax(5.25rem,1fr))]">
                                            {group.slots.map((slot) => (
                                                <button
                                                    key={slot.wire}
                                                    type="button"
                                                    className={SLOT}
                                                    value={slot.wire}
                                                    aria-pressed={state.slot === slot.wire}
                                                    onClick={() => setState(chooseSlot(state, slot.wire))}
                                                >
                                                    {slot.label}
                                                </button>
                                            ))}
                                        </div>
                                    </section>
                                ))}
                        </div>
                    </div>
                )}
                {state.step === 2 && <CustomerStep state={state} catalog={catalog} clock={clock} granularity={granularity} staffReal={staffReal} onChange={setState} words={words} />}
            </div>
        </Modal>
    );
}

/*
 * What the dialog's body holds, and what the panels measure their own width by. The one pixel at
 * its top is for the keyboard's outline on a finished step of the strip: three pixels of line
 * held two off the step need five above it, and the dialog's body, a scroller that cuts whatever
 * is drawn over its edge, leaves four.
 */
const BODY = '@container pt-px';

/*
 * A start the admin can pick: a pill as high as a touch target, its figures lining up from one
 * pill to the next, filled with the theme colour once chosen and ringed in it under the pointer.
 */
const SLOT =
    'admin-wizard-slot focus-ring h-11 rounded-field bg-card px-2 text-body font-semibold whitespace-nowrap tabular-nums inset-ring-[1.5px] inset-ring-border hover:inset-ring-2 hover:inset-ring-primary aria-pressed:bg-primary aria-pressed:text-primary-foreground aria-pressed:inset-ring-primary motion-safe:transition motion-safe:duration-200 motion-safe:ease-soft';

type StripStepProps = { at: number; name: WizardKey; value: string | undefined; step: Step; day: string; words: WizardWords; onReopen: () => void };

/*
 * One step of the strip, a card holding its number in a circle, its name and its running answer.
 * The current step is ringed in the theme colour and marked for a screen reader; it stays
 * disabled, since moving forward is Continue's job, but it is never faded, as it is the step the
 * admin is on. A finished step shows a tick in its circle and is a live button back to itself;
 * one not reached yet is disabled and faded. The circle and the short name beside it are drawn for
 * the eye alone, as a screen reader takes the step's numbered name in their place. On a phone
 * every step but the current one narrows to its circle, its answer kept for a screen reader, so
 * the current one has the room.
 */
function StripStep({ at, name, value, step, day, words, onReopen }: StripStepProps) {
    const current = at === step;
    const done = at < step;

    return (
        <button
            type="button"
            className={cn(
                'admin-wizard-step focus-ring relative flex h-full min-h-15.5 w-full items-center gap-2.5 rounded-menu bg-card px-3.5 py-3 text-left inset-ring-[1.5px] inset-ring-border disabled:cursor-default enabled:hover:inset-ring-ink-tertiary aria-[current=step]:inset-ring-2 aria-[current=step]:inset-ring-primary motion-safe:transition motion-safe:duration-200 motion-safe:ease-soft',
                at > step && 'opacity-55',
                !current && 'max-desktop:w-13 max-desktop:justify-center max-desktop:gap-0 max-desktop:px-0',
            )}
            disabled={at >= step}
            aria-current={current ? 'step' : undefined}
            value={at === 1 ? day : undefined}
            onClick={onReopen}
        >
            <span
                className={cn('admin-wizard-step__number flex size-7.5 shrink-0 items-center justify-center rounded-full font-display text-label', current || done ? 'bg-primary text-primary-foreground' : 'bg-muted text-primary-deep')}
                aria-hidden="true"
            >
                {done ? <Tick /> : at + 1}
            </span>
            <span className={cn('flex min-w-0 flex-1 flex-col', !current && 'max-desktop:flex-none')}>
                <span className="admin-wizard-step__name sr-only">{words.text('step', { n: String(at + 1), step: words.text(name) })}</span>
                <span className={cn('admin-wizard-step__title text-body font-bold', !current && 'max-desktop:sr-only')} aria-hidden="true">
                    {words.text(name)}
                </span>
                <span className={cn('admin-wizard-step__value truncate text-label font-medium text-muted-foreground', !current && 'max-desktop:sr-only')}>{value}</span>
            </span>
        </button>
    );
}

type StepProps = { state: State; catalog: Catalog; staffReal: boolean; onChange: (state: State) => void; words: WizardWords };

function Services({ state, catalog, staffReal, onChange, words }: StepProps) {
    const many = state.segments.length > 1;

    return (
        <div className="admin-wizard-services flex flex-col gap-4">
            {state.segments.map((segment, at) => (
                <Card key={at} variant="inner">
                    <fieldset className="admin-wizard-segment flex min-w-0 flex-col gap-3">
                        {many && <legend className="admin-wizard-segment__caption mb-3 font-display text-body font-semibold">{words.text('segment', { n: String(at + 1) })}</legend>}
                        <Field id={`wizard-service-${at}`} label={words.text('service')}>
                            {(control) => (
                                <Select
                                    {...control}
                                    className="admin-wizard-service"
                                    options={[{ value: '', label: words.text('choose_service') }, ...catalog.services.map((service) => ({ value: String(service.id), label: service.name }))]}
                                    value={segment.service === null ? '' : String(segment.service)}
                                    onChange={(value) => onChange(chooseService(state, at, value === '' ? null : Number(value), catalog))}
                                />
                            )}
                        </Field>
                        {staffReal && (
                            <Field id={`wizard-staff-${at}`} label={words.text('staff')}>
                                {(control) => (
                                    <Select
                                        {...control}
                                        className="admin-wizard-staff"
                                        options={[{ value: '', label: words.text(ANY_STAFF) }, ...performersOf(catalog, segment.service).map((member) => ({ value: String(member.id), label: member.name }))]}
                                        value={segment.staff === null ? '' : String(segment.staff)}
                                        onChange={(value) => onChange(chooseStaff(state, at, value === '' ? null : Number(value)))}
                                    />
                                )}
                            </Field>
                        )}
                        {many && (
                            <Button variant="tertiary" destructive className="admin-wizard-remove self-start" onClick={() => onChange(removeSegment(state, at))}>
                                {words.text('remove')}
                            </Button>
                        )}
                    </fieldset>
                </Card>
            ))}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
                <Button className="admin-wizard-add" onClick={() => onChange(addSegment(state))}>
                    {words.text('add_segment')}
                </Button>
                {many && (
                    <label className="admin-wizard-same-staff flex items-center gap-2 text-sm">
                        <input type="checkbox" className={`admin-wizard-same-staff__tick ${CHECK_BOX}`} checked={state.sameStaff} onChange={() => onChange(toggleSameStaff(state))} />
                        {words.text('same_staff')}
                    </label>
                )}
            </div>
        </div>
    );
}

/*
 * The last step: the customer's fields, and the booking summed up in an inner card that stands
 * beside them once the body has the room. The summary breaks a word longer than its line, since a
 * service's name is the tenant's own text and may hold no space to break at.
 */
function CustomerStep({ state, catalog, clock, granularity, staffReal, onChange, words }: StepProps & { clock: TenantClock; granularity: number }) {
    const start = state.slot ?? '';
    const span = start === '' ? '' : words.text('summary.span', { time: timeLabel(start, clock), end: timeLabel(endOf(start, state, catalog, granularity), clock) });
    const fields: [keyof State['customer'], WizardKey, string][] = [
        ['name', 'fields.name', 'text'],
        ['email', 'fields.email', 'email'],
        ['phone', 'fields.phone', 'tel'],
    ];

    return (
        <div className="admin-wizard-customer grid grid-cols-1 gap-6 @xl:grid-cols-[minmax(0,1fr)_18.125rem]">
            <Card variant="inner" className="@xl:col-start-2 @xl:row-start-1">
                <dl className="admin-wizard-summary flex flex-col gap-3 wrap-anywhere">
                    <div>
                        <dt className="text-small text-muted-foreground">{words.text('summary.date')}</dt>
                        <dd className="admin-wizard-summary__date font-semibold">{formatDate(state.day, clock)}</dd>
                    </div>
                    <div>
                        <dt className="text-small text-muted-foreground">{words.text('summary.time')}</dt>
                        <dd className="admin-wizard-summary__time font-semibold tabular-nums">{span}</dd>
                    </div>
                    {serviceLines(state, catalog, staffReal, words).map((line, at) => (
                        <div key={at}>
                            <dt className="text-small text-muted-foreground">{words.text('summary.service')}</dt>
                            <dd className="admin-wizard-summary__service font-semibold">{line}</dd>
                        </div>
                    ))}
                </dl>
            </Card>
            <div className="flex min-w-0 flex-col gap-3 @xl:col-start-1 @xl:row-start-1">
                {fields.map(([field, label, type]) => (
                    <Field key={field} id={`wizard-customer-${field}`} label={words.text(label)}>
                        {(control) => <TextInput {...control} type={type} className={`admin-wizard-${field}`} value={state.customer[field]} onChange={(event) => onChange(editCustomer(state, field, event.target.value))} />}
                    </Field>
                ))}
            </div>
        </div>
    );
}
