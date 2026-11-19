<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The console's rail, in one place on the server: AdminNav's twin with no permissions and no gates,
 * since an operator holds every power and the console sells nothing. Each entry names its key, the
 * one word the client chooses its icon by, its label, its URL on the console's host, its group and
 * the route it belongs to; the rail an operator
 * receives is every entry, the current one marked, and the client renders it verbatim, as the
 * admin's rail is rendered.
 *
 * Each screen's entry is in entries(), in rail order: the tenant list's first, Messages after it,
 * then Tickets, Audit last. An entry is current when the request's route is the entry's route or
 * one named under it, so a tenant's detail named under console.tenants lights Tenants, a ticket's
 * thread under console.tickets lights Tickets, and the console's 404 page lights none: under a
 * route of its own, and from a routed page answering a path naming nothing, the request
 * NotFoundController marks, as the admin's rail reads AdminNav::NO_ENTRY.
 *
 * The one count on the rail rides on Tickets: the help tickets awaiting a reply, open with the
 * business having written last, and null where none does, so the rail shows no badge reading zero.
 */
final readonly class ConsoleNav
{
    public const string PLATFORM = 'Platform';

    /**
     * The request attribute of a page no entry stands for, the console's 404 page: set, the rail
     * lights no entry whatever route answered.
     */
    public const string NO_ENTRY = 'console.nav.no-entry';

    /** The tenant list's entry, the console's home. */
    public const string TENANTS_LABEL = 'Tenants';

    /** The contact messages' entry. */
    public const string MESSAGES_LABEL = 'Messages';

    /** The help tickets' entry. */
    public const string TICKETS_LABEL = 'Tickets';

    /** The Audit screen's entry. */
    public const string AUDIT_LABEL = 'Audit';

    /** The route of the one entry that carries a count. */
    private const string TICKETS = 'console.tickets';

    /**
     * @return list<array{key: string, label: string, url: string, group: string, route: string}>
     */
    private static function entries(): array
    {
        return [
            ['key' => 'tenants', 'label' => self::TENANTS_LABEL, 'url' => '/', 'group' => self::PLATFORM, 'route' => 'console.tenants'],
            ['key' => 'messages', 'label' => self::MESSAGES_LABEL, 'url' => '/messages', 'group' => self::PLATFORM, 'route' => 'console.messages'],
            ['key' => 'tickets', 'label' => self::TICKETS_LABEL, 'url' => '/tickets', 'group' => self::PLATFORM, 'route' => self::TICKETS],
            ['key' => 'audit', 'label' => self::AUDIT_LABEL, 'url' => '/audit', 'group' => self::PLATFORM, 'route' => 'console.audit'],
        ];
    }

    /**
     * The rail as the nav prop carries it, in the admin rail's shape, the count on Tickets alone.
     *
     * @param  int  $awaiting  the help tickets awaiting a reply, App\Help\HelpDesk::awaiting
     * @return list<array{key: string, label: string, url: string, group: string, pending: int|null, current: bool}>
     */
    public static function for(?string $route, int $awaiting): array
    {
        return array_map(fn (array $entry): array => [
            'key' => $entry['key'],
            'label' => $entry['label'],
            'url' => $entry['url'],
            'group' => $entry['group'],
            'pending' => $entry['route'] === self::TICKETS && $awaiting > 0 ? $awaiting : null,
            'current' => $route !== null && ($route === $entry['route'] || str_starts_with($route, $entry['route'].'.')),
        ], self::entries());
    }
}
