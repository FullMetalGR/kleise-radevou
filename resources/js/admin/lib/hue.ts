/*
 * A member's hue: the Calendar's blocks, heads and Who rows, the Shifts rows and the Staff
 * screen's dots ring or fill a member in it. This is the one place it is decided: the member's
 * own colour where they picked one on the Staff screen, else the automatic spread of the palette
 * over the ids.
 */

/**
 * The fixed palette, 5.3: seven hues with colour-vision-deficient separation validated, the
 * Okabe and Ito set less black, which is the text's. Ids differing by a multiple of seven share
 * a hue under the automatic spread, accepted deliberately.
 */
export const PALETTE: readonly string[] = ['#e69f00', '#56b4e9', '#009e73', '#f0e442', '#0072b2', '#d55e00', '#cc79a7'];

/**
 * The palette's colours by the word a member's own colour is stored as, staff.colour and
 * App\Tenancy\StaffColour, in the palette's order: each word names the hue at its place.
 */
export const STAFF_COLOURS = ['orange', 'sky_blue', 'green', 'yellow', 'blue', 'vermilion', 'purple'] as const;

export type StaffColour = (typeof STAFF_COLOURS)[number];

/** The members who picked a colour, as the Calendar and the Shifts screen are handed them; a member absent is on Automatic. */
export type OwnColours = readonly { id: number; colour: StaffColour }[];

/** The one grey every block wears where the staff choice is not real. */
export const NEUTRAL = '#8a8a8a';

/** The palette's hue at a place, the place spread over the seven: the automatic choice, and a category's hue. */
export function paletteHue(place: number): string {
    return PALETTE[((place % PALETTE.length) + PALETTE.length) % PALETTE.length] ?? NEUTRAL;
}

/** The hue a colour word names. */
export function colourHue(colour: StaffColour): string {
    return paletteHue(STAFF_COLOURS.indexOf(colour));
}

/** A member's hue: their own colour where they picked one, else the spread over their id. */
export function memberHue(member: { id: number; colour: StaffColour | null }): string {
    return member.colour === null ? paletteHue(member.id) : colourHue(member.colour);
}

/** The hue for a staff member by id, read through the members who picked a colour. */
export function hueOf(staffId: number, own: OwnColours): string {
    return memberHue({ id: staffId, colour: own.find((member) => member.id === staffId)?.colour ?? null });
}
