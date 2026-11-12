import type { ReactNode } from 'react';
import { Notice } from '@/components/ui/notice';

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
 * ladder and never reaches it. The three waiting states render one house way: loading a spinner
 * alone, error a non-dismissible error notice in the screen's own sentence, notFound a Back to
 * the list link over a non-dismissible warning notice.
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

function Spinner({ label }: { label: string }) {
    return <span className="admin-spinner block size-6 animate-spin rounded-full border-2 border-current border-t-transparent" role="status" aria-label={label} />;
}

type DetailProps<T> = {
    rows: readonly T[];
    state: ListState;
    /** The row's id, or 'new' for the create route, which branches before the ladder. */
    id: number | 'new';
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

export function ListDetail<T extends { readonly id: number }>({ rows, state, id, loadingLabel, errorSentence, notFoundSentence, backLabel, listHref, onBack, create, children }: DetailProps<T>) {
    if (id === 'new') {
        return <>{create}</>;
    }

    const resolution = resolve(rows, state, (row) => row.id === id);

    switch (resolution.kind) {
        case 'loading':
            return <Spinner label={loadingLabel} />;
        case 'error':
            return (
                <Notice kind="error" className="admin-detail-error">
                    {errorSentence}
                </Notice>
            );
        case 'notFound':
            return (
                <div className="admin-detail-missing flex flex-col gap-3">
                    <a
                        href={listHref}
                        className="admin-detail-back self-start underline"
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
            );
        case 'found':
            return <>{children(resolution.row)}</>;
    }
}
