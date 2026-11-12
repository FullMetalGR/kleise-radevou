<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

/**
 * The scripts the admin's phone walks evaluate in the page, in one place for the reason
 * FlowScripts gives. A length the layout computes can be a fraction of a pixel, so every
 * comparison allows one.
 */
final class PhoneScripts
{
    /** True while nothing on the page reaches past the right edge of the screen, so the page never scrolls sideways. */
    public const string NO_SIDEWAYS_SCROLL = 'document.documentElement.scrollWidth <= window.innerWidth + 1';

    /** Day's member columns wider than their row, which scrolls sideways. */
    public const string DAY_COLUMNS_SCROLL = "(() => { const row = document.querySelector('.admin-calendar-columns__row'); return row !== null && row.scrollWidth > row.clientWidth && getComputedStyle(row).overflowX === 'auto'; })()";

    /** The viewport's width, to read that the walk runs on a phone. */
    public const string VIEWPORT_WIDTH = 'window.innerWidth';

    /**
     * True when the open dialog's frame covers the whole viewport, its body is the one scroller,
     * and its footer sits inside the screen, so a long body cannot push the actions away.
     */
    public const string DIALOG_FILLS_SCREEN = <<<'JS'
        (() => {
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
            return near(box.left, 0) && near(box.top, 0) && near(box.width, window.innerWidth) && near(box.height, window.innerHeight)
                && scroller && foot.bottom <= window.innerHeight + 1 && foot.top >= 0;
        })()
        JS;

    /** True when the open dialog's body is longer than the room it has, and scrolls inside it. */
    public const string DIALOG_BODY_SCROLLS = <<<'JS'
        (() => {
            const body = document.querySelector('.admin-modal__frame--filling .admin-modal__body');
            return body !== null && body.scrollHeight > body.clientHeight;
        })()
        JS;

    /** True when nothing in the open dialog's body reaches past its right edge, so it never scrolls sideways. */
    public const string DIALOG_NO_SIDEWAYS = <<<'JS'
        (() => {
            const body = document.querySelector('.admin-modal__frame--filling .admin-modal__body');
            return body !== null && body.scrollWidth <= body.clientWidth + 1;
        })()
        JS;

    /**
     * True when, in every row of the open dialog's items table, each cell's text stands at least
     * half a rem clear of the next cell's, so two columns at the phone's width never read as one.
     */
    public const string ITEMS_CELLS_APART = <<<'JS'
        (() => {
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
        })()
        JS;

    /** True when the wizard's day calendar stands over the day's times rather than beside them. */
    public const string WIZARD_DAY_OVER_TIMES = <<<'JS'
        (() => {
            const grid = document.querySelector('.admin-wizard-grid');
            const times = document.querySelector('.admin-wizard-times');
            return grid !== null && times !== null && times.getBoundingClientRect().top >= grid.getBoundingClientRect().bottom;
        })()
        JS;

    /**
     * True when every toast showing sits inside the screen from side to side and clear of the
     * open dialog's pinned footer, so an error toast waiting to be dismissed covers no action.
     */
    public const string TOASTS_INSIDE = <<<'JS'
        (() => {
            const toasts = Array.from(document.querySelectorAll('.admin-toast'));
            const footer = document.querySelector('.admin-modal__footer');
            const top = footer === null ? window.innerHeight : footer.getBoundingClientRect().top;
            return toasts.length > 0 && toasts.every((toast) => {
                const box = toast.getBoundingClientRect();
                return box.left >= 0 && box.right <= window.innerWidth + 1 && box.bottom <= top;
            });
        })()
        JS;

    /** True when the rail's drawer stands at the left edge, as tall as the screen and narrower than it. */
    public const string DRAWER_AT_EDGE = <<<'JS'
        (() => {
            const drawer = document.querySelector('.admin-modal__frame--drawer');
            if (!drawer) {
                return false;
            }
            const box = drawer.getBoundingClientRect();
            return Math.abs(box.left) <= 1 && Math.abs(box.top) <= 1 && Math.abs(box.height - window.innerHeight) <= 1 && box.width < window.innerWidth;
        })()
        JS;

    /** True while the element holding focus is the top bar's rail control. */
    public const string FOCUS_ON_MENU = "document.activeElement !== null && document.activeElement.classList.contains('admin-menu')";

    /** True when the sticky save bar's bottom edge sits inside the screen, wherever the form is scrolled. */
    public const string SAVE_BAR_INSIDE = <<<'JS'
        (() => {
            const bar = document.querySelector('.admin-save-bar');
            if (!bar) {
                return false;
            }
            const box = bar.getBoundingClientRect();
            return box.bottom <= window.innerHeight + 1 && box.left >= 0 && box.right <= window.innerWidth + 1;
        })()
        JS;
}
