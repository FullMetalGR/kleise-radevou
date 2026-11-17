import type { Ref } from 'react';
import { Button } from '@/components/ui/button';
import { formatCount } from '@/lib/format';
import { closedCount, type Setup } from './model';
import type { SetupWords } from './words';

/*
 * The setup banner above Home's content while setup is open: how many of the steps that apply are
 * done or skipped, and Continue, which opens the wizard on the first open step. Nothing dismisses
 * it: it goes once setup is complete. The count wraps beside Continue and under it on a narrow
 * screen, the button never squeezed.
 */
export function SetupBanner({ setup, words, onContinue, continueRef }: { setup: Setup; words: SetupWords; onContinue: () => void; continueRef?: Ref<HTMLButtonElement> }) {
    const progress = words.text('banner.progress', { done: formatCount(closedCount(setup), words.locale), total: formatCount(setup.steps.length, words.locale) });

    return (
        <section className="admin-setup-banner flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-primary/40 bg-primary/5 px-4 py-3" aria-label={words.text('title')}>
            <p className="admin-setup-banner__progress min-w-0 flex-1 basis-40 text-sm font-medium">{progress}</p>
            <Button ref={continueRef} variant="primary" className="admin-setup-banner__continue" onClick={onContinue}>
                {words.text('banner.continue')}
            </Button>
        </section>
    );
}
