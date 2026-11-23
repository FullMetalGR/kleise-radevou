/*
 * The arcs: four rings about one point near a corner of a card, decoration alone. The caller
 * places their square box by two of its edges and its width, in pixels, and names the theme
 * colour they are drawn in and how strongly, as a percent; the colour is a theme token, so the
 * rings follow every theme in light and dark.
 *
 * The layer fills the card and clips the rings to the card's rounded box itself, so the card
 * need not clip what it holds: a focus ring or a shadow inside it still shows whole. The card
 * holding it is positioned and isolated (relative isolate), which keeps the layer, set below
 * everything else, over the card's own fill and under its words. It is hidden from assistive
 * technology and takes no press.
 *
 * In dark mode the layer draws them at three quarters of the strength given. There the theme's
 * colour is a light one on a near-black fill, so at the same strength a ring stands out further
 * than it does in light mode and lifts the fill under a light word toward the word's own colour;
 * at three quarters the rings read about as plainly as in light mode and every word they cross
 * keeps its contrast.
 */

type Tone = 'primary' | 'attention' | 'positive';

type Edges = { top?: number; right?: number; bottom?: number; left?: number };

const TONES: Record<Tone, string> = {
    primary: 'text-primary',
    attention: 'text-attention',
    positive: 'text-positive',
};

const RADII = [40, 74, 108, 142] as const;

export function Arcs({ edges, width, tone, strength }: { edges: Edges; width: number; tone: Tone; strength: number }) {
    return (
        <span aria-hidden="true" className="admin-arcs pointer-events-none absolute inset-0 -z-10 overflow-hidden rounded-[inherit] dark:opacity-75">
            <svg viewBox="0 0 200 200" className={`absolute ${TONES[tone]}`} style={{ ...edges, width, height: width, opacity: strength / 100 }} fill="none" stroke="currentColor" strokeWidth="1.2">
                {RADII.map((radius) => (
                    <circle key={radius} cx="150" cy="150" r={radius} />
                ))}
            </svg>
        </span>
    );
}
