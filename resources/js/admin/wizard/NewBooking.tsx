import { createContext, type ReactNode, useContext, useState } from 'react';
import { todayIn } from '../../booking/time';
import { wizardWordsOf } from '@/customers/words';
import { clockOf } from '@/lib/format';
import { useShell } from '@/lib/strings';
import type { SharedProps } from '@/types';
import { BookingWizard } from './BookingWizard';
import type { Prefill } from './model';

/*
 * The wizard's opening: one place that knows whether it is open and with what prefill, provided to
 * the whole shell so the top bar's New booking button and a calendar slot open it over whatever
 * screen is showing, and a customer's page's Book now opens it with the customer. Each opening
 * mounts a fresh wizard, so nothing of a closed one survives into the next. The server hands a
 * catalog to a user who may book by hand and null to everybody else, and the wizard exists only
 * for the first; its words ride beside the catalog by the same rule, read in the page's language.
 */

type Opening = { open: (prefill?: Prefill) => void };

const Context = createContext<Opening | null>(null);

export function useNewBooking(): Opening {
    const opening = useContext(Context);

    if (opening === null) {
        throw new Error('useNewBooking() is used outside the NewBookingProvider.');
    }

    return opening;
}

type Props = { props: SharedProps; children: ReactNode };

export function NewBookingProvider({ props, children }: Props) {
    const [opening, setOpening] = useState<{ prefill: Prefill; count: number } | null>(null);
    const open = (prefill: Prefill = {}) => setOpening((current) => ({ prefill, count: (current?.count ?? 0) + 1 }));
    const catalog = props.catalog ?? null;
    const words = wizardWordsOf(props.wizard, useShell().language);

    return (
        <Context.Provider value={{ open }}>
            {children}
            {opening !== null && catalog !== null && (
                <BookingWizard
                    key={opening.count}
                    prefill={opening.prefill}
                    catalog={catalog}
                    clock={clockOf(props)}
                    granularity={props.settings.granularity}
                    weekStart={props.settings.week_start}
                    entitlements={props.entitlements}
                    today={todayIn(props.tenant.timezone, Date.now())}
                    onClose={() => setOpening(null)}
                    words={words}
                />
            )}
        </Context.Provider>
    );
}
