/*
 * Setup as Home's `setup` prop carries it to an owner while it is open: the steps that apply, in
 * the order the wizard walks them, each with whether its fact holds and whether it was skipped;
 * whether the wizard ever came up by itself; the step it opens on by itself on this answer, or
 * null; and each step's own data, null for a step that needs none. A step is closed once done or
 * skipped, and setup is complete once every step is closed, when Home's answer carries no setup
 * at all. Team and Online payments alone can be skipped, and only while still open.
 */

export type StepKey = 'profile' | 'services' | 'team' | 'hours' | 'payments' | 'link';

export type StepFact = { key: StepKey; done: boolean; skipped: boolean };

export type Setup = {
    steps: StepFact[];
    opened: boolean;
    opens: StepKey | null;
    data: Partial<Record<StepKey, unknown>>;
};

const SKIPPABLE: readonly StepKey[] = ['team', 'payments'];

export function isClosed(fact: StepFact): boolean {
    return fact.done || fact.skipped;
}

/** How many of the steps that apply are closed. */
export function closedCount(setup: Setup): number {
    return setup.steps.filter(isClosed).length;
}

/** The first step still open, Business profile where none is. */
export function firstOpen(setup: Setup): StepKey {
    return (setup.steps.find((fact) => !isClosed(fact)) ?? setup.steps[0])?.key ?? 'profile';
}

/** Whether the footer offers Skip for now on this step: Team or Online payments while still open. */
export function skippable(fact: StepFact): boolean {
    return SKIPPABLE.includes(fact.key) && !isClosed(fact);
}
