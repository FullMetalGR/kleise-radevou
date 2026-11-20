import { useEffect, useState } from 'react';

/*
 * Whether the bottom bar steps out of the way of the page. It goes as the page scrolls down past
 * its first stretch, so the reader has the whole screen for what they scroll towards, and comes
 * back the moment they scroll up, which is how a reader looks for a way out. It stays near the
 * page's end, where the last controls are and nothing more will scroll in under it, and on a page
 * too short to scroll at all. A move of a few pixels either way is a hand's tremor or an
 * elastic bounce, so it neither moves the bar nor counts as where the page now is.
 */

/** How far down the page must be before a scroll down hides the bar. */
const FIRST_STRETCH = 120;

/** How close to the page's end the bar is held on screen. */
const NEAR_END = 80;

/** The smallest move that counts as a scroll. */
const TREMOR = 6;

export function useHiddenOnScroll(): boolean {
    const [hidden, setHidden] = useState(false);

    useEffect(() => {
        let last = window.scrollY;

        const read = () => {
            const y = window.scrollY;
            const room = document.documentElement.scrollHeight - window.innerHeight;

            if (room <= 0 || room - y <= NEAR_END) {
                last = y;
                setHidden(false);

                return;
            }

            const moved = y - last;

            if (Math.abs(moved) < TREMOR) {
                return;
            }

            last = y;
            setHidden(moved > 0 && y > FIRST_STRETCH);
        };

        window.addEventListener('scroll', read, { passive: true });

        return () => window.removeEventListener('scroll', read);
    }, []);

    return hidden;
}
