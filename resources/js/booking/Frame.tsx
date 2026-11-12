import type { ReactNode, Ref } from 'react';
import type { Mode } from './attributes';
import type { Step } from './flow';

/*
 * The flow container: one element carrying the mode, the shell's state and, once the flow is up,
 * the current step, so all three are externally observable in tests and in support. Manage mode has
 * no step and carries which of the four views of 6.1 is on screen instead, for the same reason. It
 * is focusable without being in the tab order, because a customer-initiated transition lands focus
 * here, on the container and never on a first control, and nothing else ever focuses it.
 */

type ShellState = 'loading' | 'ready' | 'unavailable';

interface FrameProps {
    readonly mode: Mode;
    readonly state: ShellState;
    readonly step?: Step;
    readonly view?: string;
    readonly ref?: Ref<HTMLDivElement>;
    readonly children: ReactNode;
}

export function Frame({ mode, state, step, view, ref, children }: FrameProps): ReactNode {
    return (
        <div
            ref={ref}
            className="kr-booking__frame"
            data-kr-mode={mode}
            data-kr-state={state}
            data-kr-step={step}
            data-kr-view={view}
            aria-busy={state === 'loading'}
            tabIndex={-1}
        >
            {children}
        </div>
    );
}
