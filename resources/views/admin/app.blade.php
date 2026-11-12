{{--
    The admin's root view. Its lang is the language the page is answered in, the shared props'
    language: the signed-in user's, else the tenant default. The sign-in page answered past its
    limit is rendered before the shared props are, so it hands its own language, the tenant
    default the host names; the application's locale stands behind it for any page that names
    none. The body's data-theme is the tenant's named theme, the shared props' theme, which the
    stylesheet keys its colour blocks on; a page that names none is neutral.
--}}
<!DOCTYPE html>
<html lang="{{ $page['props']['language'] ?? app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title inertia>{{ config('site.name') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite('resources/js/admin/app.tsx', \App\Http\Middleware\HandleInertiaRequests::BUILD_DIRECTORY)
    @inertiaHead
</head>
<body data-theme="{{ \App\Tenancy\Theme::read($page['props']['theme'] ?? null)->value }}">
    @inertia
</body>
</html>
