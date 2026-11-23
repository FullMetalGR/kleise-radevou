<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

/**
 * The scripts the admin's phone walks evaluate in the page, in one place for the reason
 * FlowScripts gives. A length the layout computes can be a fraction of a pixel, so every
 * comparison allows one. A width is held against the box the document is laid out in and never
 * against the window's own: a phone zooms out to fit a page that is too wide and the window then
 * reports the wider figure, under which anything on that page would pass.
 *
 * A script that reads a size or a position answers once the page's faces have loaded. The admin
 * draws its text in a fallback face until its own two arrive, and the same words are another
 * width in another face, so a reading taken before then is of a layout about to move. A page too
 * wide moves the window's figures with it, as above, so a box that holds no text waits as well.
 * The promise is the browser's own: asked for, it lays the page out first, so text that entered
 * a moment ago has asked for its face by the time the promise is handed over.
 */
final class PhoneScripts
{
    /**
     * True while nothing on the page reaches past the right edge of the screen, so the page never
     * scrolls sideways: the document is no wider than the box it is laid out in.
     */
    public const string NO_SIDEWAYS_SCROLL = 'document.fonts.ready.then(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)';

    /**
     * The Team screen's members as rows: each member a block whose cells show their column name,
     * and the table's box no wider than the room it has, so nothing scrolls sideways.
     */
    public const string TEAM_TABLE_AS_ROWS = "document.fonts.ready.then(() => { const box = document.querySelector('.admin-team-members .admin-table-scroll'); const rows = [...document.querySelectorAll('.admin-team-member')]; const label = document.querySelector('.admin-team-member .admin-table__label'); return box !== null && rows.length > 0 && rows.every((row) => getComputedStyle(row).display === 'block') && label !== null && getComputedStyle(label).display !== 'none' && box.scrollWidth <= box.clientWidth + 1; })";

    /**
     * The Team screen's longest email, sixty characters with no break, wrapped onto more than one
     * line inside its row: the value no wider than its room, and the row inside the table's box.
     */
    public const string LONG_EMAIL_WRAPS = "document.fonts.ready.then(() => { const value = [...document.querySelectorAll('.admin-team-member td .admin-table__value')].find((cell) => (cell.textContent ?? '').length >= 60); if (value === undefined) { return false; } const box = document.querySelector('.admin-team-members .admin-table-scroll').getBoundingClientRect(); const row = value.closest('tr').getBoundingClientRect(); const line = parseFloat(getComputedStyle(value).lineHeight); return value.scrollWidth <= value.clientWidth + 1 && value.getBoundingClientRect().height > line * 1.5 && row.left >= box.left - 1 && row.right <= box.right + 1; })";

    /** True when the help bubble stands whole inside the screen's width. */
    public const string BUBBLE_INSIDE = "document.fonts.ready.then(() => { const bubble = document.querySelector('".Help::BUBBLE."'); if (bubble === null) { return false; } const box = bubble.getBoundingClientRect(); return box.left >= 0 && box.right <= document.documentElement.clientWidth + 1; })";

    /** Day's member columns wider than their row, which scrolls sideways. */
    public const string DAY_COLUMNS_SCROLL = "document.fonts.ready.then(() => { const row = document.querySelector('.admin-calendar-columns__row'); return row !== null && row.scrollWidth > row.clientWidth && getComputedStyle(row).overflowX === 'auto'; })";

    /** The viewport's width, to read that the walk runs on a phone. */
    public const string VIEWPORT_WIDTH = 'document.fonts.ready.then(() => window.innerWidth)';

    /** The language the page says it is in. */
    public const string PAGE_LANGUAGE = "document.documentElement.getAttribute('lang')";

    /**
     * True when the open dialog's frame covers the whole viewport, its body is the one scroller,
     * and its footer sits inside the screen, so a long body cannot push the actions away.
     */
    public const string DIALOG_FILLS_SCREEN = <<<'JS'
        document.fonts.ready.then(() => {
            const frame = document.querySelector('.admin-modal__frame--filling');
            const body = frame?.querySelector('.admin-modal__body');
            const footer = frame?.querySelector('.admin-modal__footer');
            if (!frame || !body || !footer) {
                return false;
            }
            const near = (a, b) => Math.abs(a - b) <= 1;
            const box = frame.getBoundingClientRect();
            const foot = footer.getBoundingClientRect();
            const scroller = getComputedStyle(body).overflowY === 'auto';
            return near(box.left, 0) && near(box.top, 0) && near(box.width, document.documentElement.clientWidth) && near(box.height, window.innerHeight)
                && scroller && foot.bottom <= window.innerHeight + 1 && foot.top >= 0;
        })
        JS;

    /** True when the open dialog's body is longer than the room it has, and scrolls inside it. */
    public const string DIALOG_BODY_SCROLLS = <<<'JS'
        document.fonts.ready.then(() => {
            const body = document.querySelector('.admin-modal__frame--filling .admin-modal__body');
            return body !== null && body.scrollHeight > body.clientHeight;
        })
        JS;

    /** True when nothing in the open dialog's body reaches past its right edge, so it never scrolls sideways. */
    public const string DIALOG_NO_SIDEWAYS = <<<'JS'
        document.fonts.ready.then(() => {
            const body = document.querySelector('.admin-modal__frame--filling .admin-modal__body');
            return body !== null && body.scrollWidth <= body.clientWidth + 1;
        })
        JS;

    /**
     * True when every button in the footer of each open dialog stands inside that dialog's frame
     * from side to side. A footer sets its actions against its end edge, so a row of them wider
     * than the footer runs out of the frame's start edge, where no scroll reaches it and no scroll
     * size grows to tell of it: each button's own box is held to the frame's.
     */
    public const string DIALOG_FOOTER_BUTTONS_INSIDE = <<<'JS'
        document.fonts.ready.then(() => {
            const frames = Array.from(document.querySelectorAll('.admin-modal__frame')).filter((frame) => frame.querySelector(':scope > .admin-modal__footer') !== null);
            return frames.length > 0 && frames.every((frame) => {
                const box = frame.getBoundingClientRect();
                const buttons = Array.from(frame.querySelectorAll(':scope > .admin-modal__footer button'));
                return buttons.length > 0 && buttons.every((button) => {
                    const own = button.getBoundingClientRect();
                    return own.left >= box.left - 1 && own.right <= box.right + 1;
                });
            });
        })
        JS;

    /**
     * True when the topmost open centred dialog is a sheet at the bottom edge: its bottom the
     * screen's, as wide as the screen, rounded at the top and square at the foot, its top below
     * the screen's and its height no more than 94 percent of the screen's.
     */
    public const string SHEET_AT_BOTTOM = <<<'JS'
        document.fonts.ready.then(() => {
            const frame = Array.from(document.querySelectorAll('.admin-modal__frame--centred')).at(-1);
            if (!frame) {
                return false;
            }
            const near = (a, b) => Math.abs(a - b) <= 1;
            const box = frame.getBoundingClientRect();
            const style = getComputedStyle(frame);
            return near(box.bottom, window.innerHeight) && near(box.left, 0) && near(box.width, document.documentElement.clientWidth)
                && box.top > 1 && box.height <= window.innerHeight * 0.94 + 1
                && parseFloat(style.borderTopLeftRadius) > 0 && parseFloat(style.borderBottomLeftRadius) === 0;
        })
        JS;

    /**
     * True when the topmost open centred dialog is a card in the middle of the screen: no wider
     * than 780 pixels, as far from either side, clear of the top and the bottom edge by the
     * backdrop's 24 pixels and as far from the one as from the other, so a card resting at the
     * foot of that room does not pass, and rounded at the foot as at the top.
     */
    public const string CARD_IN_MIDDLE = <<<'JS'
        document.fonts.ready.then(() => {
            const frame = Array.from(document.querySelectorAll('.admin-modal__frame--centred')).at(-1);
            if (!frame) {
                return false;
            }
            const near = (a, b) => Math.abs(a - b) <= 1;
            const box = frame.getBoundingClientRect();
            const width = document.documentElement.clientWidth;
            const style = getComputedStyle(frame);
            return box.width <= 781 && near(box.left, width - box.right) && box.top >= 23 && box.bottom <= window.innerHeight - 23
                && Math.abs(box.top - (window.innerHeight - box.bottom)) <= 2
                && parseFloat(style.borderBottomLeftRadius) > 0 && near(parseFloat(style.borderBottomLeftRadius), parseFloat(style.borderTopLeftRadius));
        })
        JS;

    /**
     * True when every item of the open dialog's items table reads as a block of lines, each line
     * a cell with its column's label showing beside its value, the label's text at least half a
     * rem clear of the value's so the two never read as one, every block and line inside the
     * dialog's frame from side to side, and the table's box not scrolling sideways. A cell with no
     * value has nothing for its label to run into.
     */
    public const string ITEMS_AS_ROWS = <<<'JS'
        document.fonts.ready.then(() => {
            const frame = document.querySelector('.admin-modal__frame--filling');
            const box = document.querySelector('.admin-booking-items')?.closest('.admin-table-scroll');
            const rows = Array.from(document.querySelectorAll('.admin-booking-items__row'));
            if (!frame || !box || rows.length === 0) {
                return false;
            }
            const edge = frame.getBoundingClientRect();
            const inside = (own, outer) => own.left >= outer.left - 1 && own.right <= outer.right + 1;
            const textBox = (part) => {
                const range = document.createRange();
                range.selectNodeContents(part);
                return range.getBoundingClientRect();
            };
            return box.scrollWidth <= box.clientWidth + 1 && rows.every((row) => {
                const block = row.getBoundingClientRect();
                const cells = Array.from(row.children);
                return getComputedStyle(row).display === 'block' && inside(block, edge) && cells.length > 1 && cells.every((cell) => {
                    const label = cell.querySelector('.admin-table__label');
                    const value = cell.querySelector('.admin-table__value');
                    return label !== null && value !== null && getComputedStyle(label).display !== 'none' && (label.textContent ?? '') !== ''
                        && inside(cell.getBoundingClientRect(), block)
                        && ((value.textContent ?? '') === '' || textBox(value).left - textBox(label).right >= 8 - 1);
                });
            });
        })
        JS;

    /** True when the wizard's week of chips stands over its date field, and both over the day's times rather than beside them. */
    public const string WIZARD_DAY_OVER_TIMES = <<<'JS'
        document.fonts.ready.then(() => {
            const week = document.querySelector('.admin-wizard-week');
            const field = document.querySelector('.admin-wizard-date');
            const times = document.querySelector('.admin-wizard-times');
            return week !== null && field !== null && times !== null
                && field.getBoundingClientRect().top >= week.getBoundingClientRect().bottom
                && times.getBoundingClientRect().top >= field.getBoundingClientRect().bottom;
        })
        JS;

    /**
     * True when every toast showing sits inside the screen from side to side and clear of the
     * open dialog's pinned footer, so an error toast waiting to be dismissed covers no action.
     */
    public const string TOASTS_INSIDE = <<<'JS'
        document.fonts.ready.then(() => {
            const toasts = Array.from(document.querySelectorAll('.admin-toast'));
            const footer = document.querySelector('.admin-modal__footer');
            const top = footer === null ? window.innerHeight : footer.getBoundingClientRect().top;
            return toasts.length > 0 && toasts.every((toast) => {
                const box = toast.getBoundingClientRect();
                return box.left >= 0 && box.right <= document.documentElement.clientWidth + 1 && box.bottom <= top;
            });
        })
        JS;

    /**
     * True when the menu's drawer stands at the left edge, as tall as the screen, no wider than 340
     * pixels and no wider than 88 parts in a hundred of the screen, and holds everything it lists
     * inside its own width, so nothing in it runs past its edge.
     */
    public const string DRAWER_AT_EDGE = <<<'JS'
        document.fonts.ready.then(() => {
            const drawer = document.querySelector('.admin-modal__frame--drawer');
            const body = drawer?.querySelector('.admin-modal__body');
            if (!drawer || !body) {
                return false;
            }
            const box = drawer.getBoundingClientRect();
            const room = document.documentElement.clientWidth;
            return Math.abs(box.left) <= 1 && Math.abs(box.top) <= 1 && Math.abs(box.height - window.innerHeight) <= 1
                && box.width <= 341 && box.width <= room * 0.88 + 1
                && body.scrollWidth <= body.clientWidth + 1;
        })
        JS;

    /** True while the element holding focus is the first row's Menu button. */
    public const string FOCUS_ON_MENU = "document.activeElement !== null && document.activeElement.classList.contains('admin-menu-button')";

    /** The words under the icons of the bar along the foot of the screen, in order, its Menu button's last. */
    public const string BAR_LABELS = "document.fonts.ready.then(() => [...document.querySelectorAll('.admin-bottom-bar .admin-bottom-bar__label')].map((label) => label.textContent))";

    /** True while the bar along the foot of the screen is on the page and drawn nowhere. */
    public const string BAR_HIDDEN = "(() => { const bar = document.querySelector('.admin-bottom-bar'); return bar !== null && getComputedStyle(bar).display === 'none'; })()";

    /** How far the page scrolls, in pixels: its height past the window's. */
    public const string PAGE_ROOM = 'document.fonts.ready.then(() => document.documentElement.scrollHeight - window.innerHeight)';

    /**
     * A script resolving true once the bar along the foot of the screen stands where it is asked
     * to, slid away with its whole box below the window's foot or whole inside the window, and
     * false after two seconds. The bar moves a moment after the scroll or the focus that moves
     * it, once the page has heard of either, so the reading waits for it.
     */
    public static function barIs(bool $away): string
    {
        $there = $away ? 'box.top >= window.innerHeight' : 'box.height > 0 && box.top >= 0 && box.bottom <= window.innerHeight + 1';

        return <<<JS
            new Promise((resolve) => {
                const started = Date.now();
                const check = () => {
                    const box = document.querySelector('.admin-bottom-bar')?.getBoundingClientRect();
                    if (box !== undefined && {$there}) {
                        resolve(true);
                    } else if (Date.now() - started > 2000) {
                        resolve(false);
                    } else {
                        setTimeout(check, 25);
                    }
                };
                check();
            })
            JS;
    }

    /** The same reading, taken once the page has been scrolled to the offset given, in pixels from its top. */
    public static function barAfterScrollTo(int $offset, bool $away): string
    {
        return sprintf('(window.scrollTo(0, %d), %s)', $offset, self::barIs($away));
    }

    /**
     * The parts that float over the foot of the screen, the bottom bar, the save bar, the help
     * bubble, each toast and the help panel, as the names of those drawn and the pairs of them
     * whose boxes overlap: none should, whatever is up at once. A part drawn nowhere, or moved
     * wholly off the screen, is left out. The reading waits for the faces and two frames, so a part
     * that has just moved is read where it came to rest.
     */
    public const string FLOATERS_APART = <<<'JS'
        document.fonts.ready.then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
            const parts = [
                ['bar', '.admin-bottom-bar'],
                ['save', '.admin-save-bar'],
                ['bubble', '.admin-help-bubble'],
                ['toast', '.admin-toast'],
                ['panel', '.admin-help-panel'],
            ].flatMap(([name, selector]) => [...document.querySelectorAll(selector)].map((element) => [name, element.getBoundingClientRect()]))
                .filter(([, box]) => box.width > 0 && box.height > 0 && box.top < window.innerHeight && box.bottom > 0);
            const overlaps = [];
            parts.forEach(([name, box], index) => {
                parts.slice(index + 1).forEach(([other, next]) => {
                    if (box.left < next.right - 1 && next.left < box.right - 1 && box.top < next.bottom - 1 && next.top < box.bottom - 1) {
                        overlaps.push(name + '/' + other);
                    }
                });
            });
            resolve({ drawn: [...new Set(parts.map(([name]) => name))], overlaps });
        }))))
        JS;

    /** True when the sticky save bar's bottom edge sits inside the screen, wherever the form is scrolled. */
    public const string SAVE_BAR_INSIDE = <<<'JS'
        document.fonts.ready.then(() => {
            const bar = document.querySelector('.admin-save-bar');
            if (!bar) {
                return false;
            }
            const box = bar.getBoundingClientRect();
            return box.bottom <= window.innerHeight + 1 && box.left >= 0 && box.right <= document.documentElement.clientWidth + 1;
        })
        JS;

    /**
     * True when, with the page scrolled to its end, the last control before the save bar ends at
     * or above the bar's top: the bar floats while the form scrolls and comes to rest after the
     * form at its end, so it never covers the form's last field. The scroll is the script's own,
     * and the reading waits two frames for the page to settle where it was put.
     */
    public const string SAVE_BAR_CLEARS_LAST_CONTROL = <<<'JS'
        document.fonts.ready.then(() => new Promise((resolve) => {
            const bar = document.querySelector('.admin-save-bar');
            if (bar === null) {
                resolve(false);
                return;
            }
            document.scrollingElement.scrollTop = document.scrollingElement.scrollHeight;
            requestAnimationFrame(() => requestAnimationFrame(() => {
                const controls = Array.from(document.querySelectorAll('a[href], button, input, select, textarea'))
                    .filter((control) => !bar.contains(control) && (bar.compareDocumentPosition(control) & Node.DOCUMENT_POSITION_PRECEDING) !== 0)
                    .filter((control) => control.getClientRects().length > 0 && control.closest('.admin-help-bubble, .admin-toaster') === null);
                const last = controls.at(-1);
                resolve(last !== undefined && last.getBoundingClientRect().bottom <= bar.getBoundingClientRect().top + 1);
            }));
        }))
        JS;

    /**
     * True when the save bar ends to the left of the help bubble, so the two stand side by side at
     * the width read and neither covers the other while the bar floats over the foot of the
     * screen.
     */
    public const string SAVE_BAR_BESIDE_BUBBLE = <<<'JS'
        document.fonts.ready.then(() => {
            const bar = document.querySelector('.admin-save-bar');
            const bubble = document.querySelector('.admin-help-bubble');
            if (bar === null || bubble === null) {
                return false;
            }
            return bar.getBoundingClientRect().right <= bubble.getBoundingClientRect().left + 1;
        })
        JS;

    /**
     * True when the save bar says why Save is unavailable and the sentence stays in its own
     * column of the bar: a run of letters wider than the column, an address with no space in it,
     * is broken onto the next line rather than drawn past the column's edge, and the column ends
     * inside the bar.
     */
    public const string SAVE_BAR_REASON_INSIDE = <<<'JS'
        document.fonts.ready.then(() => {
            const bar = document.querySelector('.admin-save-bar');
            const reason = bar === null ? null : bar.querySelector('.admin-save-bar__reason');
            if (reason === null) {
                return false;
            }
            return reason.scrollWidth <= reason.clientWidth + 1 && reason.getBoundingClientRect().right <= bar.getBoundingClientRect().right + 1;
        })
        JS;

    /**
     * True when the page draws a select inside a field and each one stands inside its field from
     * side to side. A select is as wide as its longest option unless its frame holds it, and a
     * field's own box does not grow with it, so each select's box is held to its field's.
     */
    public const string SELECTS_INSIDE_FIELDS = <<<'JS'
        document.fonts.ready.then(() => {
            const selects = Array.from(document.querySelectorAll('.admin-field select'));
            return selects.length > 0 && selects.every((select) => {
                const field = select.closest('.admin-field').getBoundingClientRect();
                const own = select.getBoundingClientRect();
                return own.left >= field.left - 1 && own.right <= field.right + 1;
            });
        })
        JS;

    /** How many selects of the page's fields list an option of sixty characters or more. */
    public const string SELECTS_WITH_LONG_OPTION = "document.fonts.ready.then(() => Array.from(document.querySelectorAll('.admin-field select')).filter((select) => Array.from(select.options).some((option) => option.textContent.trim().length >= 60)).length)";

    /**
     * True when the Clear button of each search-and-pick box on the page stands inside its field
     * and inside the card around it, from side to side. The box's input and Clear share a row, and
     * an input that will not shrink pushes Clear out of the end of a narrow column.
     */
    public const string COMBOBOX_CLEAR_INSIDE = <<<'JS'
        document.fonts.ready.then(() => {
            const clears = Array.from(document.querySelectorAll('.admin-combobox__clear'));
            return clears.length > 0 && clears.every((clear) => {
                const own = clear.getBoundingClientRect();
                return ['.admin-field', '.admin-section'].every((around) => {
                    const box = clear.closest(around)?.getBoundingClientRect();
                    return box !== undefined && own.left >= box.left - 1 && own.right <= box.right + 1;
                });
            });
        })
        JS;
}
