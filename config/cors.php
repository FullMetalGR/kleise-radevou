<?php

/*
 * Cross-origin requests to the public API. Permissive on purpose: the embed runs on the
 * tenant's own domain, and an origin allowlist would have to be per tenant, would break the
 * moment a tenant added a staging domain, and would stop nobody, because a script that
 * ignores CORS is one curl away. The protections that do work are rate limiting and the fact
 * that every write is re-validated under lock. The paths are the API's and nothing else: the
 * hosted page and the admin are same-origin surfaces.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
