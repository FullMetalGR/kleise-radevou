import type { ReactNode } from 'react';
import { CRUMB_LINK } from '@/components/ui/breadcrumb';
import { Notice } from '@/components/ui/notice';
import { PageHeader } from '@/components/ui/page-header';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

/*
 * The settledness of a list, decided in this one place for every list of the admin and for the
 * list-detail scaffold Services and Staff share.
 *
 * The empty-state gate: with rows, the content and nothing else; with none while the list is in
 * flight or errored, nothing at all; only then the empty panel. A fetch failure puts a
 * non-dismissible error notice in the screen's own words above whatever the gate renders, and
 * suppresses the empty panel.
 *
 * The resolution ladder, in strict precedence: loading, then error, then notFound when the list
 * has settled and holds no row with the id, then found. A create route branches before the
 * ladder and never reaches it. The three waiting states render one house way, each under the
 * page header titled with the screen's own name, so the page keeps its one main heading and the
 * first row the shell's parts join while it waits: loading a spinner, error a non-dismissible
 * error notice in the screen's own sentence, notFound a Back to the list link, in the
 * breadcrumb's link look, over a non-dismissible warning notice.
 */

export type ListState = { readonly loading: boolean; readonly error: boolean };

/** A list is settled once it is neither in flight nor errored; only then may it answer "none". */
function settled(state: ListState): boolean {
    return !state.loading && !state.error;
}

export function listGate(count: number, state: ListState): 'content' | 'nothing' | 'empty' {
    if (count > 0) {
        return 'content';
    }

    return settled(state) ? 'empty' : 'nothing';
}

export type Resolution<T> = { kind: 'loading' } | { kind: 'error' } | { kind: 'notFound' } | { kind: 'found'; row: T };

export function resolve<T>(rows: readonly T[], state: ListState, matches: (row: T) => boolean): Resolution<T> {
    if (state.loading) {
        return { kind: 'loading' };
    }

    if (state.error) {
        return { kind: 'error' };
    }

    const row = rows.find(matches);

    return row === undefined ? { kind: 'notFound' } : { kind: 'found', row };
}

type Props = {
    count: number;
    state: ListState;
    errorSentence: string;
    empty: ReactNode;
    children: ReactNode;
};

export function SettledList({ count, state, errorSentence, empty, children }: Props) {
    const gate = listGate(count, state);

    return (
        <>
            {state.error && (
                <Notice kind="error" className="admin-list-error">
                    {errorSentence}
                </Notice>
            )}
            {gate === 'content' && children}
            {gate === 'empty' && empty}
        </>
    );
}

type DetailProps<T> = {
    rows: readonly T[];
    state: ListState;
    /** The row's id, or 'new' for the create route, which branches before the ladder. */
    id: number | 'new';
    /** The screen's own name, the main heading of each waiting state. */
    title: string;
    loadingLabel: string;
    errorSentence: string;
    notFoundSentence: string;
    /** The words of the not-found state's link back to the list. */
    backLabel: string;
    listHref: string;
    onBack: () => void;
    create: ReactNode;
    children: (row: T) => ReactNode;
};

/** A waiting state under the page header, whose title row the loaded screen's header also holds. */
function Waiting({ title, children }: { title: string; children: ReactNode }) {
    return (
        <>
            <PageHeader title={title} />
            {children}
        </>
    );
}

export function ListDetail<T extends { readonly id: number }>({ rows, state, id, title, loadingLabel, errorSentence, notFoundSentence, backLabel, listHref, onBack, create, children }: DetailProps<T>) {
    if (id === 'new') {
        return <>{create}</>;
    }

    const resolution = resolve(rows, state, (row) => row.id === id);

    switch (resolution.kind) {
        case 'loading':
            return (
                <Waiting title={title}>
                    <Spinner label={loadingLabel} />
                </Waiting>
            );
        case 'error':
            return (
                <Waiting title={title}>
                    <Notice kind="error" className="admin-detail-error">
                        {errorSentence}
                    </Notice>
                </Waiting>
            );
        case 'notFound':
            return (
                <Waiting title={title}>
                    <div className="admin-detail-missing flex flex-col gap-3">
                        <a
                            href={listHref}
                            className={cn('admin-detail-back self-start', CRUMB_LINK)}
                            onClick={(event) => {
                                event.preventDefault();
                                onBack();
                            }}
                        >
                            {backLabel}
                        </a>
                        <Notice kind="warning" className="admin-detail-missing__notice">
                            {notFoundSentence}
                        </Notice>
                    </div>
                </Waiting>
            );
        case 'found':
            return <>{children(resolution.row)}</>;
    }
}
