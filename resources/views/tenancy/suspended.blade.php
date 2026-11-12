{{--
    The page a suspended tenant's host answers on every route. Its lang is the request's language,
    which ResolveTenant reads from the path and the row before it binds anything, and its title,
    heading and sentence are keys of pages.php read in that language, named here since no locale
    is set before the tenant group runs.
--}}
<!doctype html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('pages.suspended.title', [], $language) }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif; color: #1f2933; background: #f5f7fa; }
        main { max-width: 32rem; padding: 2rem; text-align: center; }
        h1 { margin: 0 0 0.75rem; font-size: 1.5rem; }
        p { margin: 0; line-height: 1.5; }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('pages.suspended.heading', [], $language) }}</h1>
        <p>{{ __('pages.suspended.sentence', [], $language) }}</p>
    </main>
</body>
</html>
