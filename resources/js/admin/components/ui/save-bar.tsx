import type { ReactNode } from 'react';

/*
 * The bar that holds a form's Save: a pill on the surface, a little see-through and blurring what
 * passes under it, inside a line ring under the floating shadow. It is sticky in the flow at the
 * form's foot, 14px from the bottom of the screen: while the form scrolls it floats over it, and
 * at the end of the scroll it comes to rest after the form's last control, so it never covers a
 * field. From the wide breakpoint it is as wide as what it holds; below it, the whole width.
 *
 * The line that says why Save is unavailable sits in it as a status, so a screen reader hears it
 * when it comes; with no reason there is no line. Beside the button on a phone the line has a
 * narrow column: it wraps onto as many lines as it needs, a run of letters wider than the column
 * broken, and the bar grows as tall as the line. So the bar's corners take a fixed rounding, the
 * card's, and not half its height: round enough to read as a pill around the one row of its
 * button, and a rounded card around several lines, where half the height would draw a tall
 * capsule whose ends curve in over the words.
 */

type Props = { reason?: string | null; children: ReactNode };

export function SaveBar({ reason = null, children }: Props) {
    return (
        <div className="admin-save-bar sticky bottom-3.5 z-6 flex max-w-full items-center gap-4 rounded-tile bg-card/94 px-3.5 py-3 shadow-float backdrop-blur-sm wide:w-fit">
            {children}
            {reason !== null && (
                <p className="admin-save-bar__reason min-w-0 pr-2 text-body-sm wrap-break-word text-muted-foreground" role="status">
                    {reason}
                </p>
            )}
        </div>
    );
}
