<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reserved subdomain labels
    |--------------------------------------------------------------------------
    |
    | Labels no tenant may take, because the platform needs them or because
    | a tenant holding one would pass for the platform. A configuration array
    | rather than a table: it changes with a deploy, not with data, and a
    | table invites somebody to delete a row and hand a tenant the "api"
    | subdomain. Every entry is lowercase and is itself a valid slug.
    |
    */

    'reserved_slugs' => [
        // The labels the platform and its common services need.
        'www', 'api', 'app', 'admin', 'mail', 'smtp', 'static', 'cdn', 'assets', 'status',
        'help', 'docs', 'support', 'billing', 'blog', 'dashboard', 'login', 'auth', 'webhook',
        'webhooks',

        // Mail and DNS plumbing a wildcard record would otherwise hand out.
        'imap', 'pop', 'pop3', 'mx', 'ns', 'ns1', 'ns2', 'ftp', 'sftp', 'autoconfig',
        'autodiscover', 'postmaster', 'hostmaster', 'webmaster', 'abuse', 'noreply', 'no-reply',

        // The product's own surfaces.
        'kleiseradevou', 'signup', 'register', 'checkout', 'embed', 'account', 'accounts', 'console',
        'security', 'privacy', 'terms', 'legal', 'pricing', 'about', 'contact',

        // The console's own paths that a slug would otherwise name: GET /tenants/new is the free
        // tenant's form, registered before a tenant's detail, GET /tenants/{slug}.
        'new',

        // Environments.
        'dev', 'test', 'testing', 'staging', 'stage', 'preview', 'sandbox', 'demo', 'beta',
        'localhost',
    ],

];
