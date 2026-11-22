import type { ReactNode } from 'react';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { PageHeader } from '@/components/ui/page-header';
import { Section } from '@/components/ui/section';
import { StatusChip } from '@/components/ui/status-chip';
import type { TenantClock } from '@/lib/format';
import { blackoutLabel } from './BlackoutsCard';
import { type CatalogService, type StaffMember, statusWord, type TenantUser, userLabel } from './model';
import { PatternSummary } from './StaffForm';
import { useStaff } from './words';

/*
 * A staff member's page without the staff feature: the one-value rule decides whether the staff
 * dimension shows, and changing anything about a member needs the feature. So the page keeps the
 * form's breadcrumb, title and sections and holds no control: Basics as text, Offered online among
 * them and, withheld on the implicit member as its form withholds them, the Title, the Bio and the
 * photo; the services performed by name with an inactive one marked, the repeating pattern's
 * read-only line with the link to Shifts, as the form shows it, and the member's closures, every
 * date and time spelled in the tenant's formats with no conversion, because none carries an
 * instant. There is no Save, no Delete and no closures form, since the server answers each of those
 * writes not-found without the feature. The notice the screen hands in says why. The Copy booking
 * link button stays beside the title: copying configures nothing. The page is a size container and
 * its sections sit on a grid that asks it, not the window, how wide it is: where it is at least
 * 56rem wide, Basics and the closures run across both columns and the services sit beside the
 * weekly pattern; narrower, every section is a row of its own. Each value sits just under its
 * label, however much taller a pair beside it runs, and a long word in any value breaks inside its
 * box. Every word is a key of the staff section, read through the screen's words.
 */

/** A section that runs across both columns of the wide page. */
const ACROSS = '@4xl:col-span-2';

type Props = {
    member: StaffMember;
    services: readonly CatalogService[];
    users: readonly TenantUser[];
    clock: TenantClock;
    notice: ReactNode;
    listHref: string;
    onBack: () => void;
    /** The Copy booking link control of a named member, beside the title; null for the implicit one. */
    copyLink: ReactNode;
};

export function StaffView({ member, services, users, clock, notice, listHref, onBack, copyLink }: Props) {
    const performed = services.filter((service) => member.service_ids.includes(service.id));
    const user = users.find((candidate) => candidate.id === member.user_id);
    const words = useStaff();
    const none = words.text('view.none');

    return (
        <div className="admin-staff-view @container flex flex-col gap-4">
            <PageHeader
                className="admin-staff-title"
                breadcrumb={<Breadcrumb label={words.text('form.breadcrumb')} items={[{ label: words.text('form.list'), href: listHref, onClick: onBack }, { label: member.name }]} />}
                title={member.name}
                status={<StatusChip status={member.status} label={statusWord(member.status, words)} />}
                actions={copyLink ?? undefined}
                quiet={false}
            />
            {notice}

            <div className="grid gap-4 @4xl:grid-cols-2">
                <Section id="basics" title={words.text('form.basics')} className={ACROSS}>
                    <dl className="grid content-start gap-1 text-sm">
                        <dt className="text-muted-foreground">{words.text('view.email')}</dt>
                        <dd className="admin-staff-view__email wrap-anywhere">{member.email ?? none}</dd>
                    </dl>
                    <dl className="grid content-start gap-1 text-sm">
                        <dt className="text-muted-foreground">{words.text('view.user')}</dt>
                        <dd className="admin-staff-view__user wrap-anywhere">{user === undefined ? none : userLabel(user, words)}</dd>
                    </dl>
                    <dl className="grid content-start gap-1 text-sm">
                        <dt className="text-muted-foreground">{words.text('view.online')}</dt>
                        <dd className="admin-staff-view__online">{words.text(member.online ? 'view.yes' : 'view.no')}</dd>
                    </dl>
                    {!member.is_implicit && <Presentation member={member} />}
                </Section>

                <Section id="services" title={words.text('form.services')} wide>
                    {performed.length === 0 ? (
                        <p className="admin-staff-view__none text-sm text-muted-foreground">{none}</p>
                    ) : (
                        <ul className="admin-staff-view__services flex flex-col gap-1 text-sm">
                            {performed.map((service) => (
                                <li key={service.id} className="admin-staff-view__service">
                                    {service.status === 'inactive' ? words.text('form.inactive_service', { service: service.name }) : service.name}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section id="weekly" title={words.text('form.weekly')} wide>
                    <PatternSummary pattern={member.pattern} clock={clock} />
                </Section>

                <Section id="exceptions" title={words.text('page.exceptions')} wide className={ACROSS}>
                    {member.blackouts.length === 0 ? (
                        <p className="admin-staff-view__none text-sm text-muted-foreground">{words.text('view.no_closures')}</p>
                    ) : (
                        <ul className="admin-staff-view__closures flex flex-col gap-1 text-sm">
                            {member.blackouts.map((blackout) => (
                                <li key={`${blackout.date}:${blackout.until ?? ''}:${blackout.start ?? ''}:${blackout.end ?? ''}`} className="admin-staff-view__closure flex flex-wrap gap-3">
                                    <span className="font-mono">{blackoutLabel(blackout, clock, words)}</span>
                                    {blackout.reason !== null && (
                                        <>
                                            {' '}
                                            <span className="text-muted-foreground wrap-anywhere">{blackout.reason}</span>
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            </div>
        </div>
    );
}

/** A named member's Title, Bio and photo as stored, each None where there is none. */
function Presentation({ member }: { member: StaffMember }) {
    const words = useStaff();
    const none = words.text('view.none');

    return (
        <>
            <dl className="grid content-start gap-1 text-sm">
                <dt className="text-muted-foreground">{words.text('view.title')}</dt>
                <dd className="admin-staff-view__title wrap-anywhere">{member.title ?? none}</dd>
            </dl>
            <dl className="grid content-start gap-1 text-sm @xl:col-span-2">
                <dt className="text-muted-foreground">{words.text('view.bio')}</dt>
                <dd className="admin-staff-view__bio whitespace-pre-line wrap-anywhere">{member.bio ?? none}</dd>
            </dl>
            <dl className="grid content-start gap-1 text-sm @xl:col-span-2">
                <dt className="text-muted-foreground">{words.text('view.photo')}</dt>
                <dd className="admin-staff-view__photo flex items-center gap-3">
                    {member.photo === null ? (
                        none
                    ) : (
                        <>
                            <img className="size-16 rounded-full object-cover" src={member.photo.url} alt="" />
                            <span className="text-muted-foreground">{member.photo.name}</span>
                        </>
                    )}
                </dd>
            </dl>
        </>
    );
}
