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
<body>
    @inertia
</body>
</html>
