{{--
    The head: the page's title and meta description from its language file, its canonical and its
    three alternates, each absolute on the central host; the 404 carries noindex and neither. The
    favicon is the brand's mark. The stylesheet is the site's entry of the admin build.
--}}
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $chrome->title }}</title>
    <meta name="description" content="{{ $chrome->description }}">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
@if ($chrome->noindex)
    <meta name="robots" content="noindex">
@endif
@if ($chrome->canonical !== null)
    <link rel="canonical" href="{{ $chrome->canonical }}">
@endif
@foreach ($chrome->alternates as $hreflang => $href)
    <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
@endforeach
    @vite('resources/js/site/site.css', \App\Http\Middleware\HandleInertiaRequests::BUILD_DIRECTORY)
