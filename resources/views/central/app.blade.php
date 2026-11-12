{{--
    The central host's root view, the signup's frame: the site's header and footer, from the
    site's own partials and the chrome ChromeComposer hands them, around the Inertia root the
    signup's pages render into, in the page's language and the brand's type. The title is the
    public name until a page names itself, and the favicon is the brand's mark. It carries the
    consent banner and its loader as the site's layout does, outside the Inertia root, so the
    signup's pages ask as every central page does.
--}}
<!DOCTYPE html>
<html lang="{{ $chrome->language->value }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ $chrome->name }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite('resources/js/admin/app.tsx', \App\Http\Middleware\HandleInertiaRequests::BUILD_DIRECTORY)
    @inertiaHead
</head>
<body class="flex min-h-screen flex-col bg-brand-surface font-brand text-brand-ink antialiased">
@include('site.partials.header')
    <div class="site-frame-page flex-1">
        @inertia
    </div>
@include('site.partials.footer')
@include('site.partials.consent')
</body>
</html>
