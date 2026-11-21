import type { ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { HelpLine } from '@/components/ui/field';
import { COPY_LINK, CopyLink } from '@/lib/copy';
import { cn } from '@/lib/utils';
import type { LinkRow, Links } from './model';
import { type SettingsKey, useSettings } from './words';

/*
 * The Links card of the Booking page section: the booking page's own link, the embed
 * snippet, and one row per service and per member the public catalogue offers, each with its
 * link, a copy button and its QR code to download. Every link and every code's path is the
 * server's, so nothing here builds a URL. A copy records the press for setup's Booking link
 * step, since each copies the booking link. It is an inner card inside the section, the rows of
 * each list divided by a hairline, each link in the deep primary on a line of its own.
 */

/*
 * The card's words are keys of the settings section's links group, each constant holding its
 * key under its old name.
 */

export const LINKS_TITLE = 'links.title' satisfies SettingsKey;

export const LINKS_HELP = 'links.help' satisfies SettingsKey;

export const PAGE_LINK = 'links.page' satisfies SettingsKey;

export const SERVICE_LINKS = 'links.services' satisfies SettingsKey;

export const STAFF_LINKS = 'links.staff' satisfies SettingsKey;

export const DOWNLOAD_QR = 'links.download_qr' satisfies SettingsKey;

type Props = {
    links: Links;
    /** The embed snippet's field and its own copy control, as the page renders them. */
    snippet: ReactNode;
};

function Row({ name, link, qr, file, linkClass }: { name: string; link: string; qr: string; file: string; linkClass?: string }) {
    const words = useSettings();

    return (
        <li className="admin-settings-link flex flex-col items-start gap-1.5 py-3 first:pt-0 last:pb-0">
            <span className="admin-settings-link__name text-body font-semibold">{name}</span>
            <a className={cn('admin-settings-link__url focus-ring rounded-sm text-body-sm font-semibold break-all text-primary-deep hover:underline', linkClass)} href={link} target="_blank" rel="noreferrer">
                {link}
            </a>
            <span className="flex flex-wrap items-center gap-3">
                <CopyLink link={link} label={COPY_LINK} block="admin-settings-link" records />
                <a className="admin-settings-link__qr focus-ring rounded-sm text-body-sm font-semibold text-primary-deep underline underline-offset-2" href={qr} download={file}>
                    {words.text(DOWNLOAD_QR)}
                </a>
            </span>
        </li>
    );
}

function List({ heading, kind, rows }: { heading: string; kind: string; rows: LinkRow[] }) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <div className={`admin-settings-links__${kind} flex flex-col gap-2`}>
            <h4 className="text-label text-muted-foreground">{heading}</h4>
            <ul className="flex flex-col divide-y divide-border">
                {rows.map((row) => (
                    <Row key={row.id} name={row.name} link={row.link} qr={row.qr} file={`${kind}-${row.id}-qr.svg`} />
                ))}
            </ul>
        </div>
    );
}

export function LinksCard({ links, snippet }: Props) {
    const words = useSettings();

    return (
        <Card variant="inner" className="admin-settings-links flex flex-col gap-3 @xl:col-span-2" aria-labelledby="settings-links-head">
            <h3 id="settings-links-head" className="text-body font-semibold">
                {words.text(LINKS_TITLE)}
            </h3>
            <HelpLine className="admin-settings-links__help">{words.text(LINKS_HELP)}</HelpLine>
            <ul className="admin-settings-links__page">
                <Row name={words.text(PAGE_LINK)} link={links.page.link} qr={links.page.qr} file="booking-page-qr.svg" linkClass="admin-settings-hosted__link" />
            </ul>
            {snippet}
            <List heading={words.text(SERVICE_LINKS)} kind="service" rows={links.services} />
            <List heading={words.text(STAFF_LINKS)} kind="staff" rows={links.staff} />
        </Card>
    );
}
