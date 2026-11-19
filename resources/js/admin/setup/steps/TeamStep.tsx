import { Link, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { CHECK_BOX } from '@/components/ui/check-field';
import { ErrorLine, Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { useOneWrite } from '@/lib/one-write';
import { type KeysOf, type Strings, useShell } from '@/lib/strings';
import { blankForm, canSave, type CatalogService, patchOf } from '@/staff/model';
import { type StaffWords, staffWordsOf } from '@/staff/words';
import type { SharedProps, StaffStrings } from '@/types';
import { isClosed, type Setup } from '../model';
import { type PrimaryChoice, useStepDirty, useStepPage, useStepPrimary } from '../step';
import { forgetting, onField } from './refusal';

/*
 * The Team step, only with the staff feature: the team members in a short list, each with the
 * services they perform, never the built-in member who stands for the business; and a short form
 * adding one, a name and the services they perform ticked from every service the business has,
 * none allowed, drawn and read in the Staff screen's words. Changing or removing a member, or
 * setting their hours, is that screen's, a line with its link says. The add comes back to Home
 * re-read, the list longer, the form blank again and the focus on its name, ready for the next
 * member. While the step is open with no member the footer offers Skip for now and no primary;
 * once a member exists, or the step was skipped, the wizard's Next, so a step closed with no
 * member can still be walked past. While the add is in flight the primary is busy, so the wizard
 * holds its other moves. A name typed or a service ticked counts as an unsaved edit.
 */

/** The step's add, POST, its body the Staff screen's create. */
export const TEAM_URL = '/admin/setup/team';

/** The Staff screen, where a member is changed or removed. */
const STAFF_SCREEN = '/admin/staff';

type MemberRow = { id: number; name: string; service_ids: number[] };

type TeamData = { members: MemberRow[]; services: CatalogService[] };

/** The step's own words, its group under the setup section's steps. */
export type TeamStepStrings = { name: string; help: string; empty: string; no_services: string; add: string; adding: string; wait: string; error: string; more: string; open: string };

type StepWords = Strings<KeysOf<TeamStepStrings>>;

/** What the step's form asks for. */
type Draft = { name: string; service_ids: number[] };

type DraftField = keyof Draft;

const DRAWN: Record<string, DraftField> = { name: 'name', service_ids: 'service_ids' };

const BLANK: Draft = { name: '', service_ids: [] };

/** The page as Home carries it to the step: the Staff screen's words, and setup with each step's fact. */
type Props = SharedProps & { strings: { staff?: StaffStrings }; setup?: Setup };

export function TeamStep() {
    const { data, words, setup } = useStepPage<TeamData, TeamStepStrings>();
    const { props } = usePage<Props>();
    const staff = staffWordsOf(props.strings, useShell().language);
    const closed = props.setup?.steps.some((fact) => fact.key === 'team' && isClosed(fact)) ?? false;

    return data === null ? null : <TeamPanel data={data} words={words} next={setup.text('next')} staff={staff} closed={closed} />;
}

/** A member's services by name, in the catalog's order. */
function servicesOf(member: MemberRow, services: readonly CatalogService[]): string[] {
    return services.filter((service) => member.service_ids.includes(service.id)).map((service) => service.name);
}

/**
 * The footer's primary: busy under the add's word while it is in flight; none while the footer
 * offers Skip for now instead; else the wizard's own Next.
 */
function primaryOf(next: string, adding: boolean, busy: string, skipping: boolean): PrimaryChoice {
    if (adding) {
        return { label: next, onPress: () => undefined, busy };
    }

    return skipping ? 'hidden' : null;
}

/** Whether the form holds anything typed or ticked. */
function touched(draft: Draft): boolean {
    return draft.name !== '' || draft.service_ids.length > 0;
}

type PanelProps = { data: TeamData; words: StepWords; next: string; staff: StaffWords; closed: boolean };

function TeamPanel({ data, words, next, staff, closed }: PanelProps) {
    const [draft, setDraft] = useState(BLANK);
    const [refused, setRefused] = useState<Partial<Record<DraftField, string>>>({});
    const { inFlight, visit } = useOneWrite<'add'>({ add: words.text('wait') });
    const adding = inFlight === 'add';
    const nameField = useRef<HTMLInputElement>(null);

    const tick = (id: number, on: boolean) => {
        setDraft((current) => ({ ...current, service_ids: on ? [...current.service_ids, id] : current.service_ids.filter((ticked) => ticked !== id) }));
        setRefused((current) => forgetting(current, 'service_ids'));
    };

    const add = () => {
        if (!canSave(draft)) {
            return;
        }

        visit(
            'add',
            onField(DRAWN, (field, sentence) => setRefused((current) => ({ ...current, [field]: sentence })), words.text('error')),
            () => {
                setDraft(BLANK);
                nameField.current?.focus();
            },
            (options) => router.post(TEAM_URL, patchOf({ ...blankForm(), ...draft }, false), options),
        );
    };

    // Skip for now is the footer's while the step is open, so only then is the primary left out.
    useStepPrimary(primaryOf(next, adding, words.text('adding'), !closed && data.members.length === 0));
    useStepDirty(touched(draft));

    return (
        <div className="admin-setup-team flex flex-col gap-4">
            <p className="admin-setup-team__help text-sm text-muted-foreground">{words.text('help')}</p>
            {data.members.length === 0 ? (
                <p className="admin-setup-team__empty text-sm text-muted-foreground">{words.text('empty')}</p>
            ) : (
                <ul className="admin-setup-team__list flex flex-col divide-y rounded-lg border">
                    {data.members.map((member) => (
                        <li key={member.id} className="admin-setup-team__row flex flex-col gap-0.5 px-3 py-2 text-sm">
                            <span className="admin-setup-team__name font-medium">{member.name}</span>
                            <span className="admin-setup-team__services text-muted-foreground">{servicesOf(member, data.services).join(', ') || words.text('no_services')}</span>
                        </li>
                    ))}
                </ul>
            )}
            <form
                className="admin-setup-team__form flex flex-col gap-3 rounded-lg border p-3"
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    add();
                }}
            >
                <Field id="staff-name" label={staff.text('form.name')} error={refused.name ?? null}>
                    {(control) => (
                        <TextInput
                            {...control}
                            ref={nameField}
                            value={draft.name}
                            autoComplete="off"
                            onChange={(event) => {
                                const name = event.target.value;
                                setDraft((current) => ({ ...current, name }));
                                setRefused((current) => forgetting(current, 'name'));
                            }}
                        />
                    )}
                </Field>
                <fieldset className="admin-setup-team__pick flex flex-col gap-2" aria-describedby={refused.service_ids === undefined ? undefined : 'staff-services-error'}>
                    <legend className="mb-1 text-sm font-medium">{staff.text('form.services')}</legend>
                    {data.services.length === 0 ? (
                        <p className="admin-staff-services__none text-sm text-muted-foreground">{staff.text('form.no_services')}</p>
                    ) : (
                        <ul className="admin-staff-services flex flex-col gap-2">
                            {data.services.map((service) => (
                                <li key={service.id} className="admin-staff-services__row flex items-center gap-3">
                                    <input type="checkbox" id={`staff-service-${service.id}`} className={`admin-staff-services__tick ${CHECK_BOX}`} checked={draft.service_ids.includes(service.id)} onChange={(event) => tick(service.id, event.target.checked)} />
                                    <label htmlFor={`staff-service-${service.id}`} className="admin-staff-services__name text-sm">
                                        {service.status === 'inactive' ? staff.text('form.inactive_service', { service: service.name }) : service.name}
                                    </label>
                                </li>
                            ))}
                        </ul>
                    )}
                    {refused.service_ids !== undefined && <ErrorLine id="staff-services-error">{refused.service_ids}</ErrorLine>}
                </fieldset>
                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" className="admin-setup-team__add" disabled={!canSave(draft)} busy={adding} busyLabel={words.text('adding')}>
                        {words.text('add')}
                    </Button>
                    {!canSave(draft) && (
                        <p className="admin-setup-team__reason text-sm text-muted-foreground" role="status">
                            {staff.text('form.needs_name')}
                        </p>
                    )}
                </div>
            </form>
            <p className="admin-setup-team__more text-sm text-muted-foreground">
                {words.text('more')}{' '}
                <Link className="admin-setup-team__open underline" href={STAFF_SCREEN}>
                    {words.text('open')}
                </Link>
            </p>
        </div>
    );
}
