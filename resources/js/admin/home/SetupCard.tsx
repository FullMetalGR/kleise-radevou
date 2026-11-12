import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { hoursBranch } from '@/settings/model';
import type { HomeWords } from './words';

/*
 * The setup checklist, on Home: a card at the head of the page that walks a new owner to the
 * first booking. Four facts, each with a tick or an open circle and a link to where it is done;
 * once all four are true the card is not rendered, and nothing remembers that it was. The
 * server sends the facts to a manageSettings holder alone, so a page without the prop draws
 * nothing. The hours link to the Shifts screen while the staff feature puts them there, and to
 * Settings otherwise. Every word is a key of the home section.
 */

export type Setup = { service: boolean; hours: boolean; link: boolean; booking: boolean; hosted_url: string };

type Fact = 'service' | 'hours' | 'link' | 'booking';

const SERVICE_PATH = '/admin/services/new';

const SHIFTS_PATH = '/admin/shifts';

const HOURS_PATH = '/admin/settings#section-opening-hours';

const BOOKING_PAGE_PATH = '/admin/settings#section-booking-page';

type Props = {
    setup: Setup | undefined;
    entitlements: readonly string[];
    /** Whether the New booking wizard exists for this viewer: the server sent a catalog. */
    bookable: boolean;
    onBook: () => void;
    words: HomeWords;
};

function Mark({ done, words }: { done: boolean; words: HomeWords }) {
    return done ? (
        <span className="admin-setup__mark admin-setup__mark--done bg-primary text-primary-foreground inline-flex size-5 shrink-0 items-center justify-center rounded-full">
            <svg viewBox="0 0 16 16" className="size-3" aria-hidden="true">
                <path d="M3 8.5 6.5 12 13 4.5" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
            <span className="sr-only">{words.text('setup.done')}</span>
        </span>
    ) : (
        <span className="admin-setup__mark admin-setup__mark--open inline-flex size-5 shrink-0 rounded-full border-2">
            <span className="sr-only">{words.text('setup.open')}</span>
        </span>
    );
}

function Item({ fact, done, words, children }: { fact: Fact; done: boolean; words: HomeWords; children: ReactNode }) {
    return (
        <li className="admin-setup__item flex items-center gap-2" data-fact={fact} data-done={String(done)}>
            <Mark done={done} words={words} />
            {children}
        </li>
    );
}

export function SetupCard({ setup, entitlements, bookable, onBook, words }: Props) {
    if (setup === undefined || (setup.service && setup.hours && setup.link && setup.booking)) {
        return null;
    }

    const staffHours = hoursBranch(entitlements, null) === 'absent';

    return (
        <section className="admin-setup flex flex-col gap-3 rounded-xl border p-4" aria-labelledby="setup-title">
            <h2 id="setup-title" className="font-semibold">
                {words.text('setup.title')}
            </h2>
            <ul className="flex flex-col gap-2">
                <Item fact="service" done={setup.service} words={words}>
                    <Link href={SERVICE_PATH} className="admin-setup__link underline">
                        {words.text('setup.service')}
                    </Link>
                </Item>
                <Item fact="hours" done={setup.hours} words={words}>
                    <Link href={staffHours ? SHIFTS_PATH : HOURS_PATH} className="admin-setup__link underline">
                        {words.text('setup.hours')}
                    </Link>
                </Item>
                <Item fact="link" done={setup.link} words={words}>
                    <Link href={BOOKING_PAGE_PATH} className="admin-setup__link underline">
                        {words.text('setup.link')}
                    </Link>
                </Item>
                <Item fact="booking" done={setup.booking} words={words}>
                    {bookable && (
                        <Button variant="link" className="admin-setup__book" onClick={onBook}>
                            {words.text('setup.booking')}
                        </Button>
                    )}
                    <a href={setup.hosted_url} target="_blank" rel="noreferrer" className="admin-setup__hosted underline">
                        {bookable ? words.text('setup.hosted') : words.text('setup.booking')}
                    </a>
                </Item>
            </ul>
        </section>
    );
}
