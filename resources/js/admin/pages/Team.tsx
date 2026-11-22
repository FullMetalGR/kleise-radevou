import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Confirm } from '@/components/ui/confirm';
import { Field, HelpLine } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Segmented } from '@/components/ui/segmented';
import { Table, TableBody, TableHead, TableRow, Td, Th } from '@/components/ui/table';
import { useOneWrite } from '@/lib/one-write';
import { type KeysOf, type Strings, stringsOf, useShell } from '@/lib/strings';
import type { Failure } from '@/lib/visits';
import type { SharedProps, TeamStrings } from '@/types';

/*
 * The Team screen: the title with the count of the team on the lead line under it, the form card
 * Invite someone (Name, Email, the role as the kit's segmented control, Send invitation, and the
 * roles' sentence beneath), then the flush card Members, a table inside the table frame with one
 * row per user: the name with You for the viewer and Invitation out for a user whose link was
 * never used, the address, the staff row linked to them or None, the role as the segmented
 * control, and Send sign-in link and Remove as links.
 *
 * The screen and the invitation's card are query containers, so the invitation's row follows its
 * card, not the window: the room the card has between its sides, its padding left out. Where that
 * is less than 42rem the four controls stack, each across the card, the role's segments sharing
 * its width so a role that wraps in a language of longer words wraps into even lines. From 42rem
 * they lie on one line where they fit, the two fields sharing what the role and Send leave and
 * never narrower than 12rem; where they do not fit, Send drops under the fields to the far end,
 * and where the fields and the role do not fit one line either, the role drops with it.
 *
 * The members read as rows below the wide breakpoint and wherever their box is narrower than 64rem:
 * the grid holds two controls a row, and a narrower box would cut it at its edge. The card's
 * heading reads the same two conditions, so it keeps the rows' inset wherever they are rows. There
 * each member's role control lies under its column name across the row: the cell is a flex line
 * only in that layout, so letting it wrap and giving the value the whole line changes nothing in
 * the grid. In the grid the role's segments share their cell and wrap into it where the cell is
 * narrow, and the sign-in link's words wrap too, their lines starting at the left edge as Remove
 * under them does, so the grid fits its box from 64rem in a language of longer words. Wherever
 * the members read as rows the two links are each 44 pixels tall for a finger, the words drawn as
 * they are in the grid.
 *
 * Nothing is optimistic. A role pressed writes at once and the row shows the server's answer; one
 * write runs at a time, the pressed control busy and every other held with the wait's sentence; a
 * refusal, the last owner's or a removal of oneself among them, is the server's sentence in an
 * error toast, and a transport failure the write's own. Remove asks first through the kit's
 * confirm, and on the viewer's own row it is held with its reason, as the server refuses it. The
 * reason is text in that row for a screen reader alone, beside the button it describes: a held
 * button takes no focus, so a reader that says a description on focus alone still meets the
 * reason walking the row.
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
            <div className="admin-team-screen @container flex flex-col gap-4">
                <PageHeader title={words.text('page.title')} lead={<span className="admin-team-count">{words.choice('page.count', props.members.length)}</span>} />
                {held && (
                    <p id={WAIT_ID} role="status" className="admin-team-wait text-sm text-muted-foreground">
                        {wait}
                    </p>
                )}
                <Card variant="form" className="admin-team-invite @container flex flex-col gap-4" aria-labelledby="team-invite-title">
                    <h2 id="team-invite-title" className="text-card-title">
                        {words.text('invite.title')}
                    </h2>
                    <div className="admin-team-invite__row flex flex-col gap-3 @2xl:flex-row @2xl:flex-wrap @2xl:items-end">
                        <Field id="team-invite-name" label={words.text('invite.name')} className="@2xl:min-w-48 @2xl:flex-1">
                            {(control) => <TextInput {...control} value={name} autoComplete="off" onChange={(event) => setName(event.target.value)} />}
                        </Field>
                        <Field id="team-invite-email" label={words.text('invite.email')} className="@2xl:min-w-48 @2xl:flex-1">
                            {(control) => <TextInput {...control} type="email" value={email} autoComplete="off" onChange={(event) => setEmail(event.target.value)} />}
                        </Field>
                        <div className="flex flex-col gap-1.5 @2xl:flex-none">
                            <span id="team-invite-role" className="admin-team-invite__role-label text-label text-muted-foreground">
                                {words.text('invite.role')}
                            </span>
                            <Segmented label={words.text('invite.role')} options={roles} value={role} onChange={setRole} disabled={held} className="admin-team-invite__role *:flex-auto" />
                        </div>
                        <Button
                            variant="primary"
                            className="admin-team-invite__send @2xl:ms-auto"
                            busy={write.inFlight === 'invite'}
                            busyLabel={words.text('invite.sending')}
                            disabled={held && write.inFlight !== 'invite'}
                            aria-describedby={held && write.inFlight !== 'invite' ? WAIT_ID : undefined}
                            onClick={invite}
                        >
                            {words.text('invite.send')}
                        </Button>
                    </div>
                    <HelpLine className="admin-team-invite__help">{words.text('invite.roles_help')}</HelpLine>
                </Card>
                <Card flush className="admin-team-members" aria-labelledby="team-members-title">
                    <header className="px-6.5 pt-5 pb-3.5 max-wide:px-4.5 @max-5xl:px-4.5">
                        <h2 id="team-members-title" className="text-section-title">
                            {words.text('members.title')}
                        </h2>
                    </header>
                    <Table compact="rows-below-wider-box">
                        <TableHead>
                            <TableRow>
                                <Th>{words.text('members.name')}</Th>
                                <Th>{words.text('members.email')}</Th>
                                <Th>{words.text('members.staff_row')}</Th>
                                <Th>{words.text('members.role')}</Th>
                                <Th className="text-right">
                                    <span className="sr-only">{words.text('members.actions')}</span>
                                </Th>
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {props.members.map((member) => (
                                <TableRow key={member.id} className="admin-team-member">
                                    <Td label={words.text('members.name')} header>
                                        <span className="inline-flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{member.name}</span>
                                            {member.you && <span className="admin-chip admin-chip--past">{words.text('members.you')}</span>}
                                            {member.invited && <span className="admin-chip admin-chip--provisional">{words.text('members.invited')}</span>}
                                        </span>
                                    </Td>
                                    <Td label={words.text('members.email')}>{member.email}</Td>
                                    <Td label={words.text('members.staff_row')} className="text-muted-foreground">
                                        {member.staff ?? words.text('members.no_staff')}
                                    </Td>
                                    <Td label={words.text('members.role')} className="flex-wrap *:last:basis-full">
                                        <Segmented
                                            label={words.text('members.role_of', { name: member.name })}
                                            options={roles}
                                            value={member.role}
                                            onChange={(next) => changeRole(member, next)}
                                            disabled={held}
                                            className="admin-team-role w-full *:flex-auto"
                                        />
                                    </Td>
                                    <Td className="text-right">
                                        <span className="inline-flex flex-wrap items-center gap-x-3.5 gap-y-1.5">
                                            <Button
                                                variant="link"
                                                className="admin-team-link shrink text-left whitespace-normal max-wide:min-h-11 @max-5xl:min-h-11"
                                                busy={busy('link', member)}
                                                busyLabel={words.text('members.sending')}
                                                disabled={held && !busy('link', member)}
                                                aria-describedby={held && !busy('link', member) ? WAIT_ID : undefined}
                                                onClick={() => sendLink(member)}
                                            >
                                                {words.text('members.link')}
                                            </Button>
                                            <Button
                                                variant="link"
                                                destructive
                                                className="admin-team-remove max-wide:min-h-11 @max-5xl:min-h-11"
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
                                    </Td>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
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
