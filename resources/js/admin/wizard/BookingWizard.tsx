import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import type { WizardKey, WizardWords } from '@/customers/words';
import { DayCalendar } from '@/components/ui/day-calendar';
import { Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { Notice } from '@/components/ui/notice';
import { Select } from '@/components/ui/select';
import { formatDate, type TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
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
 * Below the admin's breakpoint, 1.6, the modal fills the viewport, the footer pinned and the body
 * the one scroller, and the two-column panels of steps 1 and 2 stack.
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
            className="admin-wizard max-w-4xl"
            onClose={onClose}
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
            <ol className="admin-wizard-strip mb-4 grid grid-cols-3 gap-2">
                {STEPS.map((name, at) => (
                    <li key={name}>
                        <button
                            type="button"
                            className="admin-wizard-step w-full rounded-lg border px-3 py-2 text-left aria-[current=step]:border-primary disabled:cursor-default max-desktop:h-full"
                            disabled={at >= state.step}
                            aria-current={at === state.step ? 'step' : undefined}
                            value={at === 1 ? state.day : undefined}
                            onClick={() => setState(reopen(state, at as Step))}
                        >
                            <span className="admin-wizard-step__name block text-sm font-medium">
                                {words.text('step', { n: String(at + 1), step: words.text(name) })}
                            </span>
                            <span className="admin-wizard-step__value block text-sm text-muted-foreground">{values[at]}</span>
                        </button>
                    </li>
                ))}
            </ol>
            {state.step === 0 && <Services state={state} catalog={catalog} staffReal={staffReal} onChange={setState} words={words} />}
            {state.step === 1 && (
                <div className="admin-wizard-when grid grid-cols-1 gap-6 desktop:grid-cols-2">
                    <DayCalendar className="admin-wizard-grid" selected={state.day} weekStart={weekStart} today={today} onChange={(day) => setState(chooseDay(state, day))} />
                    <div className="admin-wizard-times flex flex-col gap-3">
                        <p className="admin-wizard-zone text-xs text-muted-foreground">{words.text('zone', { zone: clock.timezone })}</p>
                        {times.kind === 'loading' && (
                            <p className="admin-wizard-loading text-sm text-muted-foreground" role="status">
                                <span className="admin-wizard-spinner mr-2 inline-block size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true" />
                                {words.text('loading')}
                            </p>
                        )}
                        {times.kind === 'error' && <Notice kind="error">{times.sentence}</Notice>}
                        {times.kind === 'ready' && times.starts.length === 0 && <Notice kind="info">{words.text(NO_TIMES)}</Notice>}
                        {times.kind === 'ready' &&
                            groupsOf(times.starts, clock).map((group) => (
                                <section key={group.name} className="admin-wizard-group">
                                    <h3 className="admin-wizard-group__title mb-1 text-sm font-medium">
                                        {words.text(periodKey(group.name))} ({group.slots.length})
                                    </h3>
                                    <div className="flex flex-wrap gap-1.5">
                                        {group.slots.map((slot) => (
                                            <button
                                                key={slot.wire}
                                                type="button"
                                                className="admin-wizard-slot rounded-md border px-2 py-1 font-mono text-sm aria-pressed:bg-primary aria-pressed:text-primary-foreground"
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
        </Modal>
    );
}

type StepProps = { state: State; catalog: Catalog; staffReal: boolean; onChange: (state: State) => void; words: WizardWords };

function Services({ state, catalog, staffReal, onChange, words }: StepProps) {
    const many = state.segments.length > 1;

    return (
        <div className="admin-wizard-services flex flex-col gap-4">
            {state.segments.map((segment, at) => (
                <fieldset key={at} className="admin-wizard-segment flex flex-col gap-3 rounded-lg border p-3">
                    {many && <legend className="admin-wizard-segment__caption px-1 text-sm font-medium">{words.text('segment', { n: String(at + 1) })}</legend>}
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
            ))}
            <div className="flex items-center gap-4">
                <Button className="admin-wizard-add" onClick={() => onChange(addSegment(state))}>
                    {words.text('add_segment')}
                </Button>
                {many && (
                    <label className="admin-wizard-same-staff flex items-center gap-2 text-sm">
                        <input type="checkbox" className="admin-wizard-same-staff__tick" checked={state.sameStaff} onChange={() => onChange(toggleSameStaff(state))} />
                        {words.text('same_staff')}
                    </label>
                )}
            </div>
        </div>
    );
}

function CustomerStep({ state, catalog, clock, granularity, staffReal, onChange, words }: StepProps & { clock: TenantClock; granularity: number }) {
    const start = state.slot ?? '';
    const span = start === '' ? '' : words.text('summary.span', { time: timeLabel(start, clock), end: timeLabel(endOf(start, state, catalog, granularity), clock) });
    const fields: [keyof State['customer'], WizardKey, string][] = [
        ['name', 'fields.name', 'text'],
        ['email', 'fields.email', 'email'],
        ['phone', 'fields.phone', 'tel'],
    ];

    return (
        <div className="admin-wizard-customer grid grid-cols-1 gap-6 desktop:grid-cols-2">
            <dl className="admin-wizard-summary flex flex-col gap-2 rounded-lg border p-3 text-sm">
                <div>
                    <dt className="text-muted-foreground">{words.text('summary.date')}</dt>
                    <dd className="admin-wizard-summary__date">{formatDate(state.day, clock)}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">{words.text('summary.time')}</dt>
                    <dd className="admin-wizard-summary__time font-mono">{span}</dd>
                </div>
                {serviceLines(state, catalog, staffReal, words).map((line, at) => (
                    <div key={at}>
                        <dt className="text-muted-foreground">{words.text('summary.service')}</dt>
                        <dd className="admin-wizard-summary__service">{line}</dd>
                    </div>
                ))}
            </dl>
            <div className="flex flex-col gap-3">
                {fields.map(([field, label, type]) => (
                    <Field key={field} id={`wizard-customer-${field}`} label={words.text(label)}>
                        {(control) => <TextInput {...control} type={type} className={`admin-wizard-${field}`} value={state.customer[field]} onChange={(event) => onChange(editCustomer(state, field, event.target.value))} />}
                    </Field>
                ))}
            </div>
        </div>
    );
}
