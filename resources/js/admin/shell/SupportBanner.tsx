import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { formatInstant, type TenantClock } from '@/lib/format';
import { type ShellWords, useShell } from '@/lib/strings';
import type { SharedProps } from '../types';

/*
 * The support session's banner: on every page of a session in which the platform's operator acts as
 * support, above the page content and above the narrowing banner, one whole sentence of the
 * shell's, :operator the operator's name, :end the session's end and :zone the tenant's clock,
 * naming support as the shell names it everywhere, and the session's end, sixty minutes from the
 * console's press, converted to the tenant's clock and spelled in the tenant's formats; the zone is
 * named after it, because the operator arrives from the console, whose screens keep a clock of
 * their own. End session posts the admin's sign-out, which writes the session's end, and shows its
 * busy state until the server's answer lands as the sign-in page. Nothing dismisses it, and a user,
 * whose support prop is null, sees nothing.
 *
 * The support prop's end is never null for a support principal, so nothing here guards one.
 */

type Session = NonNullable<SharedProps['support']>;

/** The banner's sentence, a key of the shell's section. */
export const SUPPORT_LINE = 'banners.support.line';

/** The banner's sentence: support named for the operator, and the end on the tenant's clock. */
export function supportLine(support: Session, clock: TenantClock, shell: ShellWords): string {
    return shell.text(SUPPORT_LINE, { operator: support.operator, end: formatInstant(support.ends_at, clock), zone: clock.timezone });
}

type Props = {
    support: Session | null;
    clock: TenantClock;
    /** The sign-out End session posted is in flight. */
    ending: boolean;
    onEnd: () => void;
};

export function SupportBanner({ support, clock, ending, onEnd }: Props) {
    const shell = useShell();

    if (support === null) {
        return null;
    }

    return (
        <Notice kind="info" className="admin-support-banner mb-4">
            <div className="flex flex-col gap-2 desktop:flex-row desktop:items-center desktop:justify-between desktop:gap-4">
                <p className="admin-support-banner__line">{supportLine(support, clock, shell)}</p>
                <Button className="admin-support-banner__end self-start desktop:self-auto" busy={ending} busyLabel={shell.text('banners.support.ending')} onClick={onEnd}>
                    {shell.text('banners.support.end')}
                </Button>
            </div>
        </Notice>
    );
}
