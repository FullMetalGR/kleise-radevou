import type { SharedProps } from '@/types';
import { Tick } from '@/components/glyphs';
import { Card } from '@/components/ui/card';
import { HelpLine } from '@/components/ui/field';
import { RadioPills } from '@/components/ui/radio-pills';
import { cn } from '@/lib/utils';
import { colourHue, PALETTE, STAFF_COLOURS, type StaffColour } from '@/lib/hue';
import { type StaffKey, useStaff } from './words';

/*
 * The Calendar colours card: Colour bookings by, the three choices as radio pills writing the
 * settings row's one word through the Calendar's own write, with a line on what the Day view does
 * with it; then By staff member, each active member's own colour as a row of swatches, Automatic
 * first and the palette's colours after it by name, each swatch a radio and its dot the hue the
 * Calendar draws. Automatic's dot is a colour wheel of the whole palette, since the spread picks
 * from all of it. A member's row is a native radio group, so it is one tab stop and the arrow keys
 * move through it; each swatch is named by its label, and the chosen one is ringed in the theme
 * colour and carries a tick beside its name, so the choice never rests on the ring alone. Nothing
 * is optimistic: the checked radio is the props' word, and a pick waits on the server, the card
 * held while a write is in flight and saying so in a status line under the colours.
 *
 * Every word is a key of the staff section; the colours' names are keys by the word stored.
 */

/** The choices of Colour bookings by, in the Calendar's order, each label a key of the staff section. */
const CHOICES = [
    { value: 'staff', label: 'colours.by_choices.staff' },
    { value: 'category', label: 'colours.by_choices.category' },
    { value: 'status', label: 'colours.by_choices.status' },
] as const satisfies readonly { value: SharedProps['settings']['colour_by']; label: StaffKey }[];

/** Automatic's dot: the palette's hues round a wheel, closing on the first, the spread drawn as one swatch. */
const AUTOMATIC = `conic-gradient(${[...PALETTE, PALETTE[0] ?? ''].join(', ')})`;

type Member = { id: number; name: string; colour: StaffColour | null };

type Props = {
    colourBy: SharedProps['settings']['colour_by'];
    members: readonly Member[];
    /** A write in flight, which holds every control of the card. */
    busy: boolean;
    onColourBy: (word: SharedProps['settings']['colour_by']) => void;
    onColour: (id: number, colour: StaffColour | null) => void;
};

/**
 * One swatch: a radio, its dot and its name, the checked one ringed in the theme colour and ticked.
 * The pill is drawn 40 pixels high and its touch area is a layer of its own reaching 2 pixels past
 * it above and below, 44 in all; the rows stand 8 pixels apart, so no two layers meet.
 */
function Swatch({ name, value, checked, swatch, label, disabled, onPick }: { name: string; value: string; checked: boolean; swatch: string; label: string; disabled: boolean; onPick: () => void }) {
    return (
        <label
            className={cn(
                'admin-staff-colours__chip relative inline-flex h-10 cursor-pointer items-center gap-2 rounded-full ps-2.5 pe-3.5 text-sm font-semibold whitespace-nowrap after:absolute after:inset-x-0 after:-inset-y-0.5 has-focus-visible:outline-3 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary has-disabled:cursor-not-allowed has-disabled:opacity-45 motion-safe:transition-colors motion-safe:duration-200',
                checked ? 'bg-muted inset-ring-[2.5px] inset-ring-primary' : 'bg-card inset-ring-[1.5px] inset-ring-border has-enabled:hover:bg-muted',
            )}
        >
            <input type="radio" className="sr-only" name={name} value={value} checked={checked} disabled={disabled} onChange={onPick} />
            <span className="admin-staff-colours__swatch size-4.5 shrink-0 rounded-full" style={swatch.startsWith('#') ? { backgroundColor: swatch } : { backgroundImage: swatch }} aria-hidden="true" />
            {label}
            {checked && <Tick />}
        </label>
    );
}

export function ColoursCard({ colourBy, members, busy, onColourBy, onColour }: Props) {
    const words = useStaff();

    return (
        <Card variant="form" className="admin-staff-colours flex min-w-0 flex-col gap-5" aria-labelledby="staff-colours-head">
            <h2 id="staff-colours-head" className="text-card-title">
                {words.text('colours.title')}
            </h2>
            <RadioPills
                name="staff-colour-by"
                legend={words.text('colours.by')}
                value={colourBy}
                options={CHOICES.map((choice) => ({ value: choice.value, label: words.text(choice.label) }))}
                disabled={busy}
                onChange={onColourBy}
                className="admin-staff-colours__by"
            >
                <HelpLine className="admin-staff-colours__help">{words.text('colours.by_help')}</HelpLine>
            </RadioPills>
            <HelpLine className="admin-staff-colours__help">{words.text('colours.members_help')}</HelpLine>
            {members.map((member) => (
                <fieldset key={member.id} className="admin-staff-colours__member flex min-w-0 flex-col" data-staff={member.id}>
                    <legend className="mb-2 text-body font-semibold wrap-anywhere">{member.name}</legend>
                    <div className="flex flex-wrap gap-2">
                        <Swatch name={`staff-colour-${member.id}`} value="" checked={member.colour === null} swatch={AUTOMATIC} label={words.text('colours.automatic')} disabled={busy} onPick={() => onColour(member.id, null)} />
                        {STAFF_COLOURS.map((colour) => (
                            <Swatch key={colour} name={`staff-colour-${member.id}`} value={colour} checked={member.colour === colour} swatch={colourHue(colour)} label={words.text(`colours.names.${colour}`)} disabled={busy} onPick={() => onColour(member.id, colour)} />
                        ))}
                    </div>
                </fieldset>
            ))}
            {busy && (
                <HelpLine role="status" className="admin-staff-colours__saving">
                    {words.text('colours.saving')}
                </HelpLine>
            )}
        </Card>
    );
}
