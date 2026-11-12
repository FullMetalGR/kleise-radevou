import { cn } from '@/lib/utils';
import { THEMES, type ThemeWord } from './model';
import { useSettings } from './words';

/*
 * The Theme picker, at the head of the Booking page section: one pill per theme in a fixed
 * order, each a swatch of the theme's light primary beside its name, the chosen one pressed and
 * ringed in its own colour, and the help under them. The choice lives in aria-pressed, as the
 * kit's segmented control keeps it, so it survives for anyone who cannot see the ring; a press
 * chooses, and the save sends it. Seven options are past the segmented control's three, and a
 * select would hide the swatches, so the pills are their own control. Each swatch is edged one
 * pixel in the kit's border token, so Neutral's near-black still reads against the dark admin's
 * background.
 */

/** Each theme's light primary, which the swatch and the ring are drawn in. */
const SWATCHES: Record<ThemeWord, string> = {
    neutral: '#171717',
    blue: '#2563eb',
    green: '#15803d',
    orange: '#c2410c',
    red: '#dc2626',
    rose: '#e11d48',
    violet: '#7c3aed',
};

type Props = {
    value: ThemeWord;
    onChange: (theme: ThemeWord) => void;
};

export function ThemePicker({ value, onChange }: Props) {
    const words = useSettings();

    return (
        <div className="admin-field flex flex-col gap-1 sm:col-span-2">
            <span id="settings-theme-label" className="admin-field__label text-sm font-medium">
                {words.text('booking_page.theme')}
            </span>
            <div role="group" aria-labelledby="settings-theme-label" aria-describedby="settings-theme-help" className="admin-settings-themes flex flex-wrap gap-2">
                {THEMES.map((theme) => {
                    const chosen = theme === value;

                    return (
                        <button
                            key={theme}
                            type="button"
                            data-value={theme}
                            aria-pressed={chosen}
                            className={cn('admin-settings-theme inline-flex items-center gap-2 rounded-full border bg-background py-1 pr-3 pl-1.5 text-sm hover:bg-muted', chosen && 'admin-settings-theme--chosen border-transparent')}
                            style={chosen ? { boxShadow: `0 0 0 2px ${SWATCHES[theme]}` } : undefined}
                            onClick={() => onChange(theme)}
                        >
                            <span className="admin-settings-theme__swatch size-4 shrink-0 rounded-full border border-border" style={{ backgroundColor: SWATCHES[theme] }} aria-hidden="true" />
                            {words.text(`booking_page.themes.${theme}`)}
                        </button>
                    );
                })}
            </div>
            <p id="settings-theme-help" className="admin-field__help text-xs text-muted-foreground">
                {words.text('booking_page.theme_help')}
            </p>
        </div>
    );
}
