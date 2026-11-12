<?php

/*
 * The admin's Inertia adapter. There is no server-side rendering: the admin is an authenticated
 * screen nobody indexes. Pages live under resources/js/admin/pages, where the test assertions
 * look for them too.
 */
return [

    'ssr' => [
        'enabled' => false,
        'url' => 'http://127.0.0.1:13714',
        'ensure_bundle_exists' => true,
    ],

    'ensure_pages_exist' => false,

    'page_paths' => [
        resource_path('js/admin/pages'),
    ],

    'page_extensions' => [
        'tsx',
    ],

    'use_script_element_for_initial_page' => false,

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [
            resource_path('js/admin/pages'),
        ],
        'page_extensions' => [
            'tsx',
        ],
    ],

    'history' => [
        'encrypt' => false,
    ],

];
