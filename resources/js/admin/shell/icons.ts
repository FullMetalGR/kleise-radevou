import {
    Building2,
    Calendar,
    CalendarOff,
    ChartColumn,
    Circle,
    Clock,
    CreditCard,
    House,
    LifeBuoy,
    List,
    type LucideIcon,
    Mail,
    Scissors,
    ScrollText,
    Settings,
    ShieldCheck,
    User,
    Users,
} from 'lucide-react';

/*
 * The rail's icons, one per menu entry, chosen by the one word the server names the entry by: the
 * admin's twelve and the console's four. The server decides which entries a person sees and what
 * they read; the client decides only how each looks, so an entry the server adds before the client
 * learns its word still renders, behind a plain circle.
 */

const ICONS: Record<string, LucideIcon> = {
    home: House,
    calendar: Calendar,
    bookings: List,
    shifts: Clock,
    services: Scissors,
    staff: Users,
    closures: CalendarOff,
    customers: User,
    reports: ChartColumn,
    team: ShieldCheck,
    settings: Settings,
    billing: CreditCard,
    tenants: Building2,
    messages: Mail,
    tickets: LifeBuoy,
    audit: ScrollText,
};

/**
 * The icon of the entry the server names by this word, a plain circle for a word it does not know.
 * Only the table's own entries count: a word every object answers to, constructor say, names no
 * icon and gets the circle too.
 */
export function navIcon(key: string): LucideIcon {
    return (Object.hasOwn(ICONS, key) ? ICONS[key] : undefined) ?? Circle;
}
