{{--
    The console's root view. The body wears the brand's scope, so the door, the shell and every
    dialog, menu, popover and toast rendered into the body take the brand's primary and the tints
    mixed from it, as one colour from the page's edge to its overlays.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title inertia>{{ config('site.name') }} console</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite('resources/js/admin/app.tsx', \App\Http\Middleware\HandleInertiaRequests::BUILD_DIRECTORY)
    @inertiaHead
</head>
<body class="admin-face brand-scope">
    @inertia
</body>
</html>
