{{--
    The footer: a link to every page of the site but the home, in the page's language, as the
    router holds them, the public name, and on a line of its own the entity line, the legal
    entity's name, address and VAT number from configuration, on the brand's paper. The signup's
    frame renders this same partial below the Inertia root. While the analytics tag's id is set it
    carries "Cookie settings", which the consent script makes reopen the banner and which is a
    link to the cookie notice where no script runs; while it is unset, nothing in its place.
--}}
    <footer class="site-footer border-t border-brand-line bg-brand-paper">
        <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center gap-6 px-6 py-8 text-sm text-brand-muted">
@if ($chrome->footer !== [])
            <nav class="site-footer-nav" aria-label="{{ __('site.footer') }}">
                <ul class="flex flex-wrap gap-4">
@foreach ($chrome->footer as $link)
                    <li><a href="{{ $link['href'] }}" class="hover:text-brand-primary hover:underline">{{ $link['label'] }}</a></li>
@endforeach
                </ul>
            </nav>
@endif
@if ($chrome->consent !== null)
            <a href="{{ $chrome->consent->notice }}" class="site-consent-settings hover:text-brand-primary hover:underline">{{ $chrome->consent->settings }}</a>
@endif
            <p class="ml-auto">{{ $chrome->name }}</p>
            <p class="site-entity basis-full text-xs">{{ $chrome->entity }}</p>
        </div>
    </footer>
