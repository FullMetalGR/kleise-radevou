{{--
    The site's layout: the head, the header and the footer around a page's main, every string of
    it from the chrome ChromeComposer hands it, dressed in the brand's tokens and type.
    Server-rendered and read in full with JavaScript off: the stylesheet is the one asset the page
    needs, and the one script of the site's own it may load is the consent banner's, while the
    analytics tag's id is set, on which no content depends. A page may carry one script besides
    that is not the site's: the contact page Cloudflare's widget, the home page the demo's embed
    from the demo's origin.
--}}
<!doctype html>
<html lang="{{ $chrome->language->value }}">
<head>
@include('site.partials.head')
</head>
<body class="flex min-h-screen flex-col bg-brand-surface font-brand text-brand-ink antialiased">
@include('site.partials.header')
    <main class="mx-auto w-full max-w-5xl flex-1 px-6 py-16">
@yield('main')
    </main>
@include('site.partials.footer')
@include('site.partials.consent')
</body>
</html>
