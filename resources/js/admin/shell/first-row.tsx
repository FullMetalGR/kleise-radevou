import { createContext, type ReactNode, useContext } from 'react';

/*
 * What the shell hands the page's first row. The row is the page's header, written by the page,
 * so the shell's parts reach it through context rather than a portal: they render in the page's
 * tree, in the row's order, and wrap with it. The start is the menu's button where the shell is
 * compact and nothing where the sidebar stands beside the page; the controls are the language,
 * the theme, the user's name and Sign out; the action is the shell's own button, asked for quiet
 * where the page has a main button of its own. Outside the shell the row is handed nothing.
 */

export type FirstRow = {
    start: ReactNode;
    controls: ReactNode;
    action: ((quiet: boolean) => ReactNode) | null;
};

const NOTHING: FirstRow = { start: null, controls: null, action: null };

const FirstRowContext = createContext<FirstRow>(NOTHING);

export function FirstRowProvider({ value, children }: { value: FirstRow; children: ReactNode }) {
    return <FirstRowContext.Provider value={value}>{children}</FirstRowContext.Provider>;
}

export function useFirstRow(): FirstRow {
    return useContext(FirstRowContext);
}
