{{--
    The header: the wordmark, the brand's mark beside the public name, linking the home in the
    page's language; the nav, the pages of SitePages::NAV the router holds; and the language
    switch, the same page in the other language, labelled in that language. The signup's frame
    renders this same partial above the Inertia root.
--}}
    <header class="site-header border-b border-brand-line bg-brand-surface">
        <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center gap-6 px-6 py-4">
            <a href="{{ $chrome->home }}" class="site-name text-xl text-brand-ink hover:text-brand-primary"><span class="brand-wordmark"><span class="brand-wordmark__mark">{{ $chrome->mark }}</span><span class="brand-wordmark__name">{{ $chrome->name }}</span></span></a>
@if ($chrome->nav !== [])
            <nav class="site-nav" aria-label="{{ __('site.nav') }}">
                <ul class="flex flex-wrap gap-4 text-sm font-medium">
@foreach ($chrome->nav as $link)
                    <li><a href="{{ $link['href'] }}" class="text-brand-ink hover:text-brand-primary">{{ $link['label'] }}</a></li>
@endforeach
                </ul>
            </nav>
@endif
            <a href="{{ $chrome->switchHref }}" hreflang="{{ $chrome->language->other()->value }}" lang="{{ $chrome->language->other()->value }}" class="site-switch ml-auto rounded-full border border-brand-line px-3 py-1 text-sm text-brand-ink hover:border-brand-primary hover:text-brand-primary">{{ $chrome->switchLabel }}</a>
        </div>
    </header>
