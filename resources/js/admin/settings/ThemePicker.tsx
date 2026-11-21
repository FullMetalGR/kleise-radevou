import { Check } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { HelpLine } from '@/components/ui/field';
import { cn } from '@/lib/utils';
import { THEMES, type ThemeWord } from './model';
import { useSettings } from './words';

/*
 * The Theme picker, at the head of the Booking page section: one pill per theme in a fixed
 * order, each a swatch of the theme's light primary beside its name, and the help under them.
 * The pills are one radio group named by the label: the chosen one is checked, ringed in the
 * primary and ticked inside its swatch, so the choice never rests on a colour alone. The group
 * is one stop of the Tab key, on the chosen pill; the arrow keys move the choice and wrap at both
 * ends, Home and End jump to the ends, and the focus follows; a key pressed with Alt, Ctrl or Cmd
 * held is a shortcut of the browser's, Alt and the left arrow its way back, and is left to it. A
 * press chooses, and the save sends it. Seven options are past the segmented control's three, and
 * a select would hide the swatches, so the pills are their own control. Each pill is drawn 40px
 * high and answers a touch 44px high, on a layer of its own reaching 2px past it, inside the 8px
 * between pills. Each swatch is edged one pixel in the kit's border token, so Neutral's
 * near-black still reads against the dark admin's background.
 */

/** Each theme's light primary, which the swatch is drawn in. */
const SWATCHES: Record<ThemeWord, string> = {
    neutral: '#171717',
    blue: '#2563eb',
    green: '#15803d',
    orange: '#c2410c',
    red: '#dc2626',
    rose: '#e11d48',
    violet: '#7c3aed',
};

/** How far each key moves the choice along the row; Home and End go to the ends. */
const STEPS: Record<string, number> = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };

/** The theme a key reaches from the chosen one, or none for a key the group leaves alone. */
function reachedBy(key: string, from: ThemeWord): ThemeWord | null {
    if (key === 'Home' || key === 'End') {
        return THEMES[key === 'Home' ? 0 : THEMES.length - 1] ?? null;
    }

    const step = STEPS[key];

    return step === undefined ? null : (THEMES[(THEMES.indexOf(from) + step + THEMES.length) % THEMES.length] ?? null);
}

type Props = {
    value: ThemeWord;
    onChange: (theme: ThemeWord) => void;
};

export function ThemePicker({ value, onChange }: Props) {
    const words = useSettings();

    const move = (event: KeyboardEvent<HTMLDivElement>) => {
        const reached = event.altKey || event.ctrlKey || event.metaKey ? null : reachedBy(event.key, value);

        if (reached === null) {
            return;
        }

        event.preventDefault();
        onChange(reached);
        event.currentTarget.querySelector<HTMLButtonElement>(`[data-value="${reached}"]`)?.focus();
    };

    return (
        <div className="admin-field flex flex-col gap-1.5 @xl:col-span-2">
            <span id="settings-theme-label" className="admin-field__label text-label text-muted-foreground">
                {words.text('booking_page.theme')}
            </span>
            <div role="radiogroup" aria-labelledby="settings-theme-label" aria-describedby="settings-theme-help" className="admin-settings-themes flex flex-wrap gap-2" onKeyDown={move}>
                {THEMES.map((theme) => {
                    const chosen = theme === value;

                    return (
                        <button
                            key={theme}
                            type="button"
                            role="radio"
                            data-value={theme}
                            aria-checked={chosen}
                            tabIndex={chosen ? 0 : -1}
                            className={cn(
                                'admin-settings-theme focus-ring relative inline-flex h-10 items-center gap-2 rounded-full pr-3.5 pl-2.5 text-body-sm font-semibold after:absolute after:-inset-0.5 motion-safe:transition-colors',
                                chosen ? 'admin-settings-theme--chosen bg-muted inset-ring-[2.5px] inset-ring-primary' : 'bg-card inset-ring-[1.5px] inset-ring-border hover:bg-muted hover:inset-ring-ink-tertiary',
                            )}
                            onClick={() => onChange(theme)}
                        >
                            <span className="admin-settings-theme__swatch inline-flex size-4.5 shrink-0 items-center justify-center rounded-full border border-border text-white" style={{ backgroundColor: SWATCHES[theme] }} aria-hidden="true">
                                {chosen ? <Check className="size-3" strokeWidth={3.5} /> : null}
                            </span>
                            {words.text(`booking_page.themes.${theme}`)}
                        </button>
                    );
                })}
            </div>
            <HelpLine id="settings-theme-help">{words.text('booking_page.theme_help')}</HelpLine>
        </div>
    );
}
