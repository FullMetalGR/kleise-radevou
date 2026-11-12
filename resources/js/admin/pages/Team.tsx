import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Confirm } from '@/components/ui/confirm';
import { Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Segmented } from '@/components/ui/segmented';
import { TableScroll } from '@/components/ui/table-scroll';
import { useOneWrite } from '@/lib/one-write';
import { type KeysOf, type Strings, stringsOf, useShell } from '@/lib/strings';
import type { Failure } from '@/lib/visits';
import type { SharedProps, TeamStrings } from '@/types';

/*
 * The Team screen: the title with the count of the team beside it, the card Invite someone (Name,
 * Email, the role as the kit's segmented control, Send invitation, and the roles' sentence
 * beneath), then the card Members, a table inside the table frame with one row per user: the name
 * with You for the viewer and Invitation out for a user whose link was never used, the address,
 * the staff row linked to them or None, the role as the segmented control, and Send sign-in link
 * and Remove.
 *
 * Nothing is optimistic. A role pressed writes at once and the row shows the server's answer; one
 * write runs at a time, the pressed control busy and every other held with the wait's sentence; a
 * refusal, the last owner's or a removal of oneself among them, is the server's sentence in an
 * error toast, and a transport failure the write's own. Remove asks first through the kit's
 * confirm, and on the viewer's own row it is held with its reason, as the server refuses it.
 *
 * Every word is a key of the team section, which TeamController names.
 */

export type Member = { id: number; name: string; email: string; role: Role; you: boolean; invited: boolean; staff: string | null };

type Role = 'owner' | 'manager' | 'staff';

type Props = SharedProps & {
    strings: SharedProps['strings'] & { team: TeamStrings };
    members: Member[];
};

type Action = 'invite' | 'role' | 'link' | 'remove';

type TeamWords = Strings<KeysOf<TeamStrings>>;

const TEAM = '/admin/team';

const ROLES: readonly Role[] = ['owner', 'manager', 'staff'];

const WAIT_ID = 'team-wait';

function rolesOf(words: TeamWords): { value: Role; label: string }[] {
    return ROLES.map((role) => ({ value: role, label: words.text(`roles.${role}`) }));
}

export default function Team() {
    const { props } = usePage<Props>();
    const shell = useShell();
    const words = stringsOf<TeamStrings>(props.strings.team, shell.language);
    const wait = words.text('wait');
    const write = useOneWrite<Action>({ invite: wait, role: wait, link: wait, remove: wait });
    const [target, setTarget] = useState<number | null>(null);
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [role, setRole] = useState<Role>('staff');
    const [removing, setRemoving] = useState<Member | null>(null);
    const held = write.pending !== null;
    const roles = rolesOf(words);

    const refused = (fallback: string, after: () => void = () => undefined) => (failure: Failure) => {
        after();

        return failure.sentence ?? fallback;
    };

    const invite = () =>
        write.visit(
            'invite',
            refused(words.text('errors.invite')),
            () => {
                setName('');
                setEmail('');
                setRole('staff');
            },
            (options) => router.post(TEAM, { name, email, role }, options),
        );

    const changeRole = (member: Member, next: Role) => {
        if (next === member.role) {
            return;
        }

        setTarget(member.id);
        write.visit('role', refused(words.text('errors.role')), () => undefined, (options) => router.put(`${TEAM}/${String(member.id)}`, { role: next }, options));
    };

    const sendLink = (member: Member) => {
        setTarget(member.id);
        write.visit('link', refused(words.text('errors.link')), () => undefined, (options) => router.post(`${TEAM}/${String(member.id)}/link`, {}, options));
    };

    const remove = (member: Member) => {
        setTarget(member.id);
        write.visit(
            'remove',
            refused(words.text('errors.remove'), () => setRemoving(null)),
            () => setRemoving(null),
            (options) => router.delete(`${TEAM}/${String(member.id)}`, options),
        );
    };

    const busy = (action: Action, member: Member) => write.inFlight === action && target === member.id;

    return (
        <>
            <Head title={words.text('page.title')} />
            <div className="admin-team-screen flex flex-col gap-4">
                <div className="flex flex-wrap items-baseline gap-3">
                    <h1 className="text-2xl font-semibold">{words.text('page.title')}</h1>
                    <span className="admin-team-count text-muted-foreground">{words.choice('page.count', props.members.length)}</span>
                </div>
                {held && (
                    <p id={WAIT_ID} role="status" className="admin-team-wait text-sm text-muted-foreground">
                        {wait}
                    </p>
                )}
                <section className="admin-team-invite flex flex-col gap-3 rounded-xl border bg-card p-4" aria-labelledby="team-invite-title">
                    <h2 id="team-invite-title" className="font-semibold">
                        {words.text('invite.title')}
                    </h2>
                    <div className="grid grid-cols-1 items-end gap-3 desktop:grid-cols-[1fr_1fr_auto_auto]">
                        <Field id="team-invite-name" label={words.text('invite.name')}>
                            {(control) => <TextInput {...control} value={name} autoComplete="off" onChange={(event) => setName(event.target.value)} />}
                        </Field>
                        <Field id="team-invite-email" label={words.text('invite.email')}>
                            {(control) => <TextInput {...control} type="email" value={email} autoComplete="off" onChange={(event) => setEmail(event.target.value)} />}
                        </Field>
                        <div className="flex flex-col gap-1">
                            <span id="team-invite-role" className="admin-team-invite__role-label text-sm font-medium">
                                {words.text('invite.role')}
                            </span>
                            <Segmented label={words.text('invite.role')} options={roles} value={role} onChange={setRole} disabled={held} className="admin-team-invite__role w-fit" />
                        </div>
                        <Button
                            variant="primary"
                            className="admin-team-invite__send"
                            busy={write.inFlight === 'invite'}
                            busyLabel={words.text('invite.sending')}
                            disabled={held && write.inFlight !== 'invite'}
                            aria-describedby={held && write.inFlight !== 'invite' ? WAIT_ID : undefined}
                            onClick={invite}
                        >
                            {words.text('invite.send')}
                        </Button>
                    </div>
                    <p className="admin-team-invite__help text-xs text-muted-foreground">{words.text('invite.roles_help')}</p>
                </section>
                <section className="admin-team-members overflow-hidden rounded-xl border bg-card" aria-labelledby="team-members-title">
                    <header className="border-b px-4 py-3">
                        <h2 id="team-members-title" className="font-semibold">
                            {words.text('members.title')}
                        </h2>
                    </header>
                    <TableScroll>
                        <table className="w-full border-collapse text-sm">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2 text-left font-semibold">{words.text('members.name')}</th>
                                    <th className="px-4 py-2 text-left font-semibold">{words.text('members.email')}</th>
                                    <th className="px-4 py-2 text-left font-semibold">{words.text('members.staff_row')}</th>
                                    <th className="px-4 py-2 text-left font-semibold">{words.text('members.role')}</th>
                                    <th className="px-4 py-2 text-right font-semibold">
                                        <span className="sr-only">{words.text('members.actions')}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {props.members.map((member) => (
                                    <tr key={member.id} className="admin-team-member">
                                        <td className="border-t px-4 py-2 align-middle">
                                            <span className="inline-flex items-center gap-2">
                                                <span className="font-medium">{member.name}</span>
                                                {member.you && <span className="admin-chip admin-chip--past">{words.text('members.you')}</span>}
                                                {member.invited && <span className="admin-chip admin-chip--provisional">{words.text('members.invited')}</span>}
                                            </span>
                                        </td>
                                        <td className="border-t px-4 py-2 align-middle">{member.email}</td>
                                        <td className="border-t px-4 py-2 align-middle text-muted-foreground">{member.staff ?? words.text('members.no_staff')}</td>
                                        <td className="border-t px-4 py-2 align-middle">
                                            <Segmented
                                                label={words.text('members.role_of', { name: member.name })}
                                                options={roles}
                                                value={member.role}
                                                onChange={(next) => changeRole(member, next)}
                                                disabled={held}
                                                className="admin-team-role"
                                            />
                                        </td>
                                        <td className="border-t px-4 py-2 text-right align-middle">
                                            <span className="inline-flex gap-1">
                                                <Button
                                                    variant="tertiary"
                                                    className="admin-team-link"
                                                    busy={busy('link', member)}
                                                    busyLabel={words.text('members.sending')}
                                                    disabled={held && !busy('link', member)}
                                                    aria-describedby={held && !busy('link', member) ? WAIT_ID : undefined}
                                                    onClick={() => sendLink(member)}
                                                >
                                                    {words.text('members.link')}
                                                </Button>
                                                <Button
                                                    variant="tertiary"
                                                    destructive
                                                    className="admin-team-remove"
                                                    disabled={member.you || held}
                                                    aria-describedby={member.you ? `team-remove-self-${String(member.id)}` : held ? WAIT_ID : undefined}
                                                    onClick={() => setRemoving(member)}
                                                >
                                                    {words.text('members.remove')}
                                                </Button>
                                                {member.you && (
                                                    <span id={`team-remove-self-${String(member.id)}`} className="sr-only">
                                                        {words.text('members.remove_self')}
                                                    </span>
                                                )}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </TableScroll>
                </section>
            </div>
            <Confirm
                open={removing !== null}
                title={words.text('members.remove_title', { name: removing?.name ?? '' })}
                name="admin-team-remove-confirm"
                confirmLabel={words.text('members.remove')}
                busyLabel={words.text('members.removing')}
                pending={words.text('members.remove_pending', { name: removing?.name ?? '' })}
                busy={write.inFlight === 'remove'}
                onConfirm={() => {
                    if (removing !== null) {
                        remove(removing);
                    }
                }}
                onDismiss={() => setRemoving(null)}
            >
                <p>{words.text('members.remove_body', { name: removing?.name ?? '' })}</p>
            </Confirm>
        </>
    );
}
