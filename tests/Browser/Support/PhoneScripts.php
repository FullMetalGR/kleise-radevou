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

    /** The Team screen's members table wider than its frame, which scrolls sideways and holds it. */
    public const string TEAM_TABLE_SCROLLS = "document.fonts.ready.then(() => { const frame = document.querySelector('.admin-team-members .admin-table-scroll'); return frame !== null && frame.scrollWidth > frame.clientWidth && getComputedStyle(frame).overflowX === 'auto'; })";

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
     * True when, in every row of the open dialog's items table, each cell's text stands at least
     * half a rem clear of the next cell's, so two columns at the phone's width never read as one.
     */
    public const string ITEMS_CELLS_APART = <<<'JS'
        document.fonts.ready.then(() => {
            const rows = Array.from(document.querySelectorAll('.admin-booking-items tr'));
            const textBox = (cell) => {
                const range = document.createRange();
                range.selectNodeContents(cell);
                return range.getBoundingClientRect();
            };
            return rows.length > 1 && rows.every((row) => {
                const boxes = Array.from(row.children).map(textBox);
                return boxes.every((box, at) => at === 0 || box.left - boxes[at - 1].right >= 8 - 1);
            });
        })
        JS;

    /** True when the wizard's day calendar stands over the day's times rather than beside them. */
    public const string WIZARD_DAY_OVER_TIMES = <<<'JS'
        document.fonts.ready.then(() => {
            const grid = document.querySelector('.admin-wizard-grid');
            const times = document.querySelector('.admin-wizard-times');
            return grid !== null && times !== null && times.getBoundingClientRect().top >= grid.getBoundingClientRect().bottom;
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

    /** True when the rail's drawer stands at the left edge, as tall as the screen and narrower than it. */
    public const string DRAWER_AT_EDGE = <<<'JS'
        document.fonts.ready.then(() => {
            const drawer = document.querySelector('.admin-modal__frame--drawer');
            if (!drawer) {
                return false;
            }
            const box = drawer.getBoundingClientRect();
            return Math.abs(box.left) <= 1 && Math.abs(box.top) <= 1 && Math.abs(box.height - window.innerHeight) <= 1 && box.width < document.documentElement.clientWidth;
        })
        JS;

    /** True while the element holding focus is the top bar's rail control. */
    public const string FOCUS_ON_MENU = "document.activeElement !== null && document.activeElement.classList.contains('admin-menu')";

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
