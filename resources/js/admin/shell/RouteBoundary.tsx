import { Component, type ErrorInfo, type ReactNode } from 'react';
import { useShell } from '@/lib/strings';

/*
 * The error boundary each page renders inside. The shell keys it on the route, so navigating
 * anywhere mounts a fresh one. On a render error it shows a notice that this screen could not be
 * displayed and the rest of the dashboard still works, then the thrown error's name, message and
 * component stack, because its audience is a developer or an operator filing a bug. It re-reports
 * to the console and never swallows. The two sentences are the shell's, in the page's language;
 * the error itself is the developer's text and stays as thrown.
 */

/** The notice, a key of the shell's section. */
export const BOUNDARY_NOTICE = 'boundary.notice';

/** The rest of the dashboard, a key of the shell's section. */
export const BOUNDARY_REST = 'boundary.rest';

/** What the boundary shows in place of a screen that threw. */
function Fallen({ error, componentStack }: { error: Error; componentStack: string }) {
    const shell = useShell();

    return (
        <section className="admin-boundary flex flex-col gap-3" role="alert">
            <h1 className="text-lg font-semibold">{shell.text(BOUNDARY_NOTICE)}</h1>
            <p>{shell.text(BOUNDARY_REST)}</p>
            <pre className="admin-boundary-error overflow-auto rounded-md bg-muted p-3 text-xs whitespace-pre-wrap">{`${error.name}: ${error.message}${componentStack}`}</pre>
        </section>
    );
}

type State = { error: Error | null; componentStack: string };

export class RouteBoundary extends Component<{ children: ReactNode }, State> {
    override state: State = { error: null, componentStack: '' };

    static getDerivedStateFromError(error: unknown): Partial<State> {
        return { error: error instanceof Error ? error : new Error(String(error)) };
    }

    override componentDidCatch(error: unknown, info: ErrorInfo): void {
        this.setState({ componentStack: info.componentStack ?? '' });
        console.error(error, info.componentStack);
    }

    override render(): ReactNode {
        const { error, componentStack } = this.state;

        if (error === null) {
            return this.props.children;
        }

        return <Fallen error={error} componentStack={componentStack} />;
    }
}
