import { Building2, Calendar, CalendarOff, ChartColumn, Circle, Clock, CreditCard, House, LifeBuoy, List, type LucideIcon, Mail, Scissors, ScrollText, Settings, ShieldCheck, User, Users } from 'lucide-react';
import { describe, expect, it } from 'vitest';
import { navIcon } from './icons';

/*
 * Each menu entry's icon, chosen by the one word the server names the entry by: the admin's
 * twelve and the console's four. A word the client does not know draws a plain circle, so an
 * entry the server adds before the client learns it still lines up with the rest.
 */

describe('navIcon', () => {
    it('draws each entry the server names by its own icon', () => {
        const icons: [string, LucideIcon][] = [
            ['home', House],
            ['calendar', Calendar],
            ['bookings', List],
            ['shifts', Clock],
            ['services', Scissors],
            ['staff', Users],
            ['closures', CalendarOff],
            ['customers', User],
            ['reports', ChartColumn],
            ['team', ShieldCheck],
            ['settings', Settings],
            ['billing', CreditCard],
            ['tenants', Building2],
            ['messages', Mail],
            ['tickets', LifeBuoy],
            ['audit', ScrollText],
        ];

        for (const [key, icon] of icons) {
            expect(navIcon(key), key).toBe(icon);
        }
    });

    it('draws a plain circle for a word it does not know', () => {
        expect(navIcon('nothing')).toBe(Circle);
    });

    it.each(['constructor', 'toString'])('draws the plain circle for %s, a word every object answers to, its own entries being the only ones it reads', (key) => {
        expect(navIcon(key)).toBe(Circle);
    });
});
