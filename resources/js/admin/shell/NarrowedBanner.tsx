import { Link } from '@inertiajs/react';
import { Notice } from '@/components/ui/notice';
import { BILLING_NAME, goneFreeSentence, goneSentence, namedFeatures } from '@/lib/features';
import { useShell } from '@/lib/strings';

/*
 * The narrowing record on every signed-in page: a warning notice above the page content with one
 * whole sentence per feature a resolve took away. It names the feature, says that each member keeps
 * their hours and bookings keep using them, and says where it comes back: a link to the Billing
 * screen for a user holding manageBilling, the screen's name in plain text for everybody else, and
 * on a free tenant, whose plan is the console's and whose Billing screen offers nothing to add,
 * platform support named with no link for anybody, the shared free prop saying which (the console
 * clears the record when it changes a free tenant's set, so on the shell's banner this is the rule
 * rather than a case seen). Nothing dismisses it: the record describes the subscription, not the
 * reader, and grace never writes one, so the banner never shows in grace.
 *
 * Each sentence is the slug's own in lib/features, rendered by FeatureGone, which the Staff screen
 * without the feature renders too, so the words are spelled once: one whole sentence in the page's
 * language, the Billing screen's name its :billing placeholder, split there to place the link or
 * the span, as payments' RATE_SLOT places the rate. The server filters the record to slugs this
 * build knows; a slug with no words in lib/features renders no sentence all the same, so an
 * arbitrary string never reaches the screen.
 */

const BILLING_URL = '/admin/billing';

type GoneProps = { slug: string; manageBilling: boolean; free: boolean; block: string };

/**
 * One feature's gone sentence as a paragraph, block__line carrying the slug in data-feature,
 * with the Billing screen's name as the link block__billing for a manageBilling holder and a
 * span of that class otherwise; on a free tenant the sentence naming platform support whole,
 * with no link and no Billing; nothing for a slug this build does not know.
 */
export function FeatureGone({ slug, manageBilling, free, block }: GoneProps) {
    const shell = useShell();
    const sentence = goneSentence(slug, shell);

    if (sentence === null) {
        return null;
    }

    if (free) {
        return (
            <p className={`${block}__line`} data-feature={slug}>
                {goneFreeSentence(slug, shell)}
            </p>
        );
    }

    const [before, after] = sentence;
    const billing = manageBilling ? (
        <Link href={BILLING_URL} className={`${block}__billing underline`}>
            {shell.text(BILLING_NAME)}
        </Link>
    ) : (
        <span className={`${block}__billing`}>{shell.text(BILLING_NAME)}</span>
    );

    return (
        <p className={`${block}__line`} data-feature={slug}>
            {before}
            {billing}
            {after}
        </p>
    );
}

type Props = { narrowed: readonly string[]; manageBilling: boolean; free: boolean };

export function NarrowedBanner({ narrowed, manageBilling, free }: Props) {
    const shell = useShell();
    const known = namedFeatures(narrowed, shell);

    if (known.length === 0) {
        return null;
    }

    return (
        <Notice kind="warning" className="admin-narrowed mb-4">
            {known.map(({ slug }) => (
                <FeatureGone key={slug} slug={slug} manageBilling={manageBilling} free={free} block="admin-narrowed" />
            ))}
        </Notice>
    );
}
