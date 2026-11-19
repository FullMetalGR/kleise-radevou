import { Link, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { formatAmount } from '@/lib/format';
import { useOneWrite } from '@/lib/one-write';
import { type KeysOf, type Strings, useShell } from '@/lib/strings';
import { blankForm, canSave, patchOf } from '@/services/model';
import { type ServicesWords, servicesWordsOf } from '@/services/words';
import type { ServicesStrings, SharedProps } from '@/types';
import { type PrimaryChoice, useStepDirty, useStepPage, useStepPrimary } from '../step';
import { forgetting, onField } from './refusal';

/*
 * The Services step: the business's services in a short list, each with its duration and its
 * price, and a short form adding one, its name, duration and price in the account's currency,
 * drawn and read in the Services screen's words. Every other field takes that screen's blank
 * value, so a service added here is the one a bare create there would make. Changing or removing
 * a service, or any other detail, is that screen's, a line with its link says. The add comes back
 * to Home re-read, the list longer, the form blank again and the focus on its name, ready for the
 * next service. The browser's own checks are off, so a duration off the grid reaches the server
 * and its sentence is said on the field. The primary is the wizard's Next, held with its reason
 * while no service exists and busy while the add is in flight. A name, a duration or a price
 * typed counts as an unsaved edit.
 */

/** The step's add, POST, its body the Services screen's create. */
export const SERVICES_URL = '/admin/setup/services';

/** The Services screen, where a service is changed or removed. */
const SERVICES_SCREEN = '/admin/services';

type ServiceRow = { id: number; name: string; duration_min: number; price_minor: number; currency: string };

type ServicesData = { services: ServiceRow[] };

/** The step's own words, its group under the setup section's steps. */
export type ServicesStepStrings = { name: string; help: string; empty: string; add: string; adding: string; wait: string; error: string; more: string; open: string; blocked: string };

type StepWords = Strings<KeysOf<ServicesStepStrings>>;

/** What the step's form asks for, as typed. */
type Draft = { name: string; duration_min: string; price: string };

type DraftField = keyof Draft;

/** The body fields the form draws, the price sent in minor units. */
const DRAWN: Record<string, DraftField> = { name: 'name', duration_min: 'duration_min', price_minor: 'price' };

type Props = SharedProps & { strings: { services?: ServicesStrings } };

export function ServicesStep() {
    const { data, words, setup } = useStepPage<ServicesData, ServicesStepStrings>();
    const { props } = usePage<Props>();
    const services = servicesWordsOf(props.strings, useShell().language);

    return data === null ? null : <ServicesPanel rows={data.services} words={words} next={setup.text('next')} services={services} currency={props.tenant.currency} granularity={props.settings.granularity} />;
}

type PanelProps = { rows: ServiceRow[]; words: StepWords; next: string; services: ServicesWords; currency: string; granularity: number };

function blankDraft(granularity: number): Draft {
    const { name, duration_min, price } = blankForm(granularity);

    return { name, duration_min, price };
}

/**
 * The footer's primary: the wizard's Next, busy under the add's word while it is in flight and
 * held with its reason while no service exists; the wizard's own once one does.
 */
function primaryOf(next: string, adding: boolean, none: boolean, words: StepWords): PrimaryChoice {
    if (adding) {
        return { label: next, onPress: () => undefined, busy: words.text('adding') };
    }

    return none ? { label: next, onPress: () => undefined, blocked: words.text('blocked') } : null;
}

/** Whether the form holds anything typed over its blank values. */
function touched(draft: Draft, blank: Draft): boolean {
    return draft.name !== blank.name || draft.duration_min !== blank.duration_min || draft.price !== blank.price;
}

function ServicesPanel({ rows, words, next, services, currency, granularity }: PanelProps) {
    const shell = useShell();
    const blank = blankDraft(granularity);
    const [draft, setDraft] = useState(blank);
    const [refused, setRefused] = useState<Partial<Record<DraftField, string>>>({});
    const { inFlight, visit } = useOneWrite<'add'>({ add: words.text('wait') });
    const adding = inFlight === 'add';
    const nameField = useRef<HTMLInputElement>(null);

    const set = (field: DraftField) => (value: string) => {
        setDraft((current) => ({ ...current, [field]: value }));
        setRefused((current) => forgetting(current, field));
    };

    const add = () => {
        if (!canSave(draft)) {
            return;
        }

        visit(
            'add',
            onField(DRAWN, (field, sentence) => setRefused((current) => ({ ...current, [field]: sentence })), words.text('error')),
            () => {
                setDraft(blankDraft(granularity));
                nameField.current?.focus();
            },
            (options) => router.post(SERVICES_URL, patchOf({ ...blankForm(granularity), ...draft }, currency, true), options),
        );
    };

    useStepPrimary(primaryOf(next, adding, rows.length === 0, words));
    useStepDirty(touched(draft, blank));

    return (
        <div className="admin-setup-services flex flex-col gap-4">
            <p className="admin-setup-services__help text-sm text-muted-foreground">{words.text('help')}</p>
            {rows.length === 0 ? (
                <p className="admin-setup-services__empty text-sm text-muted-foreground">{words.text('empty')}</p>
            ) : (
                <ul className="admin-setup-services__list flex flex-col divide-y rounded-lg border">
                    {rows.map((row) => (
                        <li key={row.id} className="admin-setup-services__row flex flex-wrap items-baseline justify-between gap-x-3 px-3 py-2 text-sm">
                            <span className="admin-setup-services__name font-medium">{row.name}</span>
                            <span className="admin-setup-services__figures flex gap-3 text-muted-foreground">
                                <span>{services.text('table.minutes', { n: String(row.duration_min) })}</span>
                                <span className="font-mono">{formatAmount(row.price_minor, row.currency, shell.locale)}</span>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <form
                className="admin-setup-services__form flex flex-col gap-3 rounded-lg border p-3"
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    add();
                }}
            >
                <Field id="service-name" label={services.text('form.name')} error={refused.name ?? null}>
                    {(control) => <TextInput {...control} ref={nameField} value={draft.name} autoComplete="off" onChange={(event) => set('name')(event.target.value)} />}
                </Field>
                <Field id="service-duration_min" label={services.text('form.duration')} help={services.text('form.duration_help', { n: String(granularity) })} error={refused.duration_min ?? null}>
                    {(control) => <TextInput {...control} type="number" min={0} step={granularity} value={draft.duration_min} onChange={(event) => set('duration_min')(event.target.value)} />}
                </Field>
                <Field id="service-price" label={services.text('form.price', { currency })} help={services.text('form.price_help')} error={refused.price ?? null}>
                    {(control) => <TextInput {...control} inputMode="decimal" className="tabular-nums" value={draft.price} onChange={(event) => set('price')(event.target.value)} />}
                </Field>
                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" className="admin-setup-services__add" disabled={!canSave(draft)} busy={adding} busyLabel={words.text('adding')}>
                        {words.text('add')}
                    </Button>
                    {!canSave(draft) && (
                        <p className="admin-setup-services__reason text-sm text-muted-foreground" role="status">
                            {services.text('form.needs_name')}
                        </p>
                    )}
                </div>
            </form>
            <p className="admin-setup-services__more text-sm text-muted-foreground">
                {words.text('more')}{' '}
                <Link className="admin-setup-services__open underline" href={SERVICES_SCREEN}>
                    {words.text('open')}
                </Link>
            </p>
        </div>
    );
}
