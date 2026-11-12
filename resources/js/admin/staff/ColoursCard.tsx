import type { SharedProps } from '@/types';
import { cn } from '@/lib/utils';
import { colourHue, PALETTE, STAFF_COLOURS, type StaffColour } from '@/lib/hue';
import { type StaffKey, useStaff } from './words';

/*
 * The Calendar colours card: Colour bookings by, the three choices as radios writing the settings
 * row's one word through the Calendar's own write, with a line on what the Day view does with it;
 * then By staff member, each active member's own colour as a row of chips, Automatic and the
 * palette's colours by name, each chip a radio and its swatch the hue the Calendar draws.
 * Automatic's swatch runs across the palette, since the spread picks from all of it. Nothing is
 * optimistic: the checked radio is the props' word, and a pick waits on the server, the card held
 * while a write is in flight and saying so in a status line under the colours.
 *
 * Every word is a key of the staff section; the colours' names are keys by the word stored.
 */

/** The choices of Colour bookings by, in the Calendar's order, each label a key of the staff section. */
const CHOICES = [
    { value: 'staff', label: 'colours.by_choices.staff' },
    { value: 'category', label: 'colours.by_choices.category' },
    { value: 'status', label: 'colours.by_choices.status' },
] as const satisfies readonly { value: SharedProps['settings']['colour_by']; label: StaffKey }[];

/** Automatic's swatch: the palette's first hue into its fifth, the spread drawn as one chip. */
const AUTOMATIC = `linear-gradient(135deg, ${PALETTE[0] ?? ''}, ${PALETTE[4] ?? ''})`;

type Member = { id: number; name: string; colour: StaffColour | null };

type Props = {
    colourBy: SharedProps['settings']['colour_by'];
    members: readonly Member[];
    /** A write in flight, which holds every control of the card. */
    busy: boolean;
    onColourBy: (word: SharedProps['settings']['colour_by']) => void;
    onColour: (id: number, colour: StaffColour | null) => void;
};

/** One chip: a radio, its swatch and its name, the checked one ringed. */
function Chip({ name, value, checked, swatch, label, disabled, onPick }: { name: string; value: string; checked: boolean; swatch: string; label: string; disabled: boolean; onPick: () => void }) {
    return (
        <label className={cn('admin-staff-colours__chip inline-flex cursor-pointer items-center gap-1 rounded-full border px-2 py-0.5 text-xs whitespace-nowrap has-[:focus-visible]:ring-3 has-[:focus-visible]:ring-ring/50', checked && 'border-primary ring-1 ring-primary')}>
            <input type="radio" className="sr-only" name={name} value={value} checked={checked} disabled={disabled} onChange={onPick} />
            <span className="admin-staff-colours__swatch inline-block size-4 rounded-full" style={swatch.startsWith('#') ? { backgroundColor: swatch } : { backgroundImage: swatch }} aria-hidden="true" />
            {label}
        </label>
    );
}

export function ColoursCard({ colourBy, members, busy, onColourBy, onColour }: Props) {
    const words = useStaff();

    return (
        <section className="admin-staff-colours flex flex-col gap-3 rounded-xl border p-4" aria-labelledby="staff-colours-head">
            <h2 id="staff-colours-head" className="font-semibold">
                {words.text('colours.title')}
            </h2>
            <fieldset className="admin-staff-colours__by flex flex-col gap-1">
                <legend className="mb-1 text-sm font-medium">{words.text('colours.by')}</legend>
                <div className="flex flex-wrap gap-4 text-sm">
                    {CHOICES.map((choice) => (
                        <label key={choice.value} className="inline-flex items-center gap-2">
                            <input type="radio" className="size-4 accent-primary" name="staff-colour-by" value={choice.value} checked={colourBy === choice.value} disabled={busy} onChange={() => onColourBy(choice.value)} />
                            {words.text(choice.label)}
                        </label>
                    ))}
                </div>
                <p className="admin-staff-colours__help text-xs text-muted-foreground">{words.text('colours.by_help')}</p>
            </fieldset>
            <p className="admin-staff-colours__help text-xs text-muted-foreground">{words.text('colours.members_help')}</p>
            {members.map((member) => (
                <fieldset key={member.id} className="admin-staff-colours__member flex flex-col gap-1.5" data-staff={member.id}>
                    <legend className="mb-1.5 text-sm font-medium">{member.name}</legend>
                    <div className="flex flex-wrap gap-2">
                        <Chip name={`staff-colour-${member.id}`} value="" checked={member.colour === null} swatch={AUTOMATIC} label={words.text('colours.automatic')} disabled={busy} onPick={() => onColour(member.id, null)} />
                        {STAFF_COLOURS.map((colour) => (
                            <Chip key={colour} name={`staff-colour-${member.id}`} value={colour} checked={member.colour === colour} swatch={colourHue(colour)} label={words.text(`colours.names.${colour}`)} disabled={busy} onPick={() => onColour(member.id, colour)} />
                        ))}
                    </div>
                </fieldset>
            ))}
            {busy && (
                <p className="admin-staff-colours__saving text-sm text-muted-foreground" role="status">
                    {words.text('colours.saving')}
                </p>
            )}
        </section>
    );
}
