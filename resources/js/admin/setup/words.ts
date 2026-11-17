import { type KeysOf, type Strings, stringsOf } from '@/lib/strings';
import type { Language } from '../../booking/language';
import type { StepKey } from './model';

/*
 * The setup wizard's words: the `setup` section of lang/{locale}/admin, which Home carries only to
 * an owner it offers setup to, read by key in the page's language. The wizard and the banner read
 * the keys typed here: the title, the step list and its phone line, the footer's buttons, the
 * question before unsaved edits are left, the toast once setup is complete, and each step's name.
 * Each step's own words sit in its group under steps beside its name, and the step reads them
 * itself through useStepPage, typed in the step's own file, so no step's keys are spelled here.
 */

export type SetupStrings = {
    title: string;
    list: string;
    position: string;
    done: string;
    skipped: string;
    close: string;
    back: string;
    next: string;
    skip: string;
    complete: string;
    discard: { title: string; body: string; confirm: string; keep: string };
    banner: { progress: string; continue: string };
    steps: Record<StepKey, { name: string }>;
};

export type SetupWords = Strings<KeysOf<SetupStrings>>;

/** The setup section a page's strings prop carries, in its language; a missing section answers the keys. */
export function setupWordsOf(strings: { setup?: SetupStrings } | undefined, language: Language): SetupWords {
    return stringsOf<SetupStrings>(strings?.setup ?? null, language);
}
