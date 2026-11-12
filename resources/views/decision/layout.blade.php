{{--
    The layout of the decision pages, the pay pages and the My bookings link page. Its lang is the
    request's language, the locale the tenant group's SetTenantLocale set for the request: the
    prefix of the path, else the tenant default.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title')</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif; color: #1f2933; background: #f5f7fa; }
        main { width: 100%; max-width: 34rem; padding: 2rem; box-sizing: border-box; }
        h1 { margin: 0 0 0.25rem; font-size: 1.35rem; }
        p { margin: 0 0 0.75rem; line-height: 1.5; }
        dl { margin: 0 0 1rem; display: grid; grid-template-columns: max-content 1fr; gap: 0.25rem 1rem; }
        dt { font-weight: 600; }
        dd { margin: 0; }
        ul { margin: 0 0 1rem; padding: 0; list-style: none; }
        li { margin: 0 0 0.75rem; }
        .when { color: #52606d; }
        .total { font-weight: 600; }
        .zone { color: #52606d; font-size: 0.875rem; }
        form { margin: 1.5rem 0 0; }
        textarea { width: 100%; box-sizing: border-box; min-height: 5rem; font: inherit; padding: 0.5rem; }
        button { font: inherit; padding: 0.6rem 1.4rem; border: 0; border-radius: 0.25rem; background: #1f2933; color: #fff; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        @yield('body')
    </main>
</body>
</html>
