import type { Ref } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatCount } from '@/lib/format';
import { closedCount, type Setup } from './model';
import type { SetupWords } from './words';

/*
 * The setup banner above Home's content while setup is open: a slim strip on the surface with the
 * count of the steps that apply done or skipped in the lime badge, the sentence that says it, and
 * Continue, which opens the wizard on the first open step. The badge is decoration, hidden from
 * assistive technology, which reads the sentence. Nothing dismisses it: it goes once setup is
 * complete. The sentence wraps beside Continue and under it on a narrow screen, the button never
 * squeezed.
 */
export function SetupBanner({ setup, words, onContinue, continueRef }: { setup: Setup; words: SetupWords; onContinue: () => void; continueRef?: Ref<HTMLButtonElement> }) {
    const done = formatCount(closedCount(setup), words.locale);
    const total = formatCount(setup.steps.length, words.locale);

    return (
        <Card className="admin-setup-banner flex flex-wrap items-center gap-x-4 gap-y-2 px-6 py-4 max-wide:px-5 max-wide:py-3.5" aria-label={words.text('title')}>
            <span aria-hidden="true" className="admin-setup-banner__count shrink-0 rounded-full bg-spark px-3.5 py-1 font-display text-xl leading-none font-semibold tabular-nums text-spark-foreground">
                {done}/<span className="text-small opacity-70">{total}</span>
            </span>
            <p className="admin-setup-banner__progress min-w-0 flex-1 basis-40 text-body font-semibold">{words.text('banner.progress', { done, total })}</p>
            <Button ref={continueRef} variant="primary" className="admin-setup-banner__continue" onClick={onContinue}>
                {words.text('banner.continue')}
            </Button>
        </Card>
    );
}
