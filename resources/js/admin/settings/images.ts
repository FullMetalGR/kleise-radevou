import type { ImageSlotWords } from './ImageSlot';
import type { SettingsKey, SettingsWords } from './words';

/*
 * The logo and the cover as their slots are drawn and written wherever they are offered: the
 * Settings screen's Booking page section and the setup wizard's Business profile step. Each has
 * its own route, POST to upload a file and DELETE to remove it, answered with a redirect back; its
 * preview's sizing; its help, chosen by the largest file's megabytes with the figure spelled as
 * :n; and the slot's words, keys of the settings section.
 */

export const LOGO_URL = '/admin/settings/logo';

export const COVER_URL = '/admin/settings/cover';

/** A header mark's preview, kept small. */
export const LOGO_PREVIEW = 'max-h-12 max-w-48';

/** A wide photo's preview, in the cover's own proportion. */
export const COVER_PREVIEW = 'aspect-[8/3] max-h-40 w-full max-w-md rounded-md object-cover';

export const LOGO_HELP = 'booking_page.logo_help' satisfies SettingsKey;

export const COVER_HELP = 'booking_page.cover_help' satisfies SettingsKey;

/** What a failed upload or removal says where the answer carries no sentence of its own. */
export const LOGO_ERROR = 'errors.logo' satisfies SettingsKey;

export const COVER_ERROR = 'errors.cover' satisfies SettingsKey;

/** The logo's and the cover's ImageSlot words, by key. */
export const LOGO_WORDS: Record<keyof ImageSlotWords, SettingsKey> = { none: 'booking_page.logo_none', upload: 'booking_page.logo_upload', replace: 'booking_page.logo_replace', remove: 'booking_page.logo_remove' };

export const COVER_WORDS: Record<keyof ImageSlotWords, SettingsKey> = { none: 'booking_page.cover_none', upload: 'booking_page.cover_upload', replace: 'booking_page.cover_replace', remove: 'booking_page.cover_remove' };

/** An ImageSlot's words, read by key. */
export function slotWords(keys: Record<keyof ImageSlotWords, SettingsKey>, words: SettingsWords): ImageSlotWords {
    return { none: words.text(keys.none), upload: words.text(keys.upload), replace: words.text(keys.replace), remove: words.text(keys.remove) };
}
