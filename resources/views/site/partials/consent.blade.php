{{--
    The consent banner and its loader, rendered by the central host's two root views alone, the
    site's layout and the signup's frame, while the analytics tag's id is set, and by nothing
    else. The banner is in the markup of every central page, hidden; the consent script, an entry
    of the admin build, shows it when no choice is stored, and once a visitor accepts it loads the
    tag from the address on it with the id beside it. One sentence names what is asked and links
    the cookie notice; Accept and Reject are the same element with the same classes, in two equal
    columns side by side, so neither outweighs the other. Every sentence is here, in the page's
    language; the script holds none. With no script the banner stays hidden and nothing ever
    loads.
--}}
@if ($chrome->consent !== null)
    <section class="site-consent fixed inset-x-0 bottom-0 z-50 border-t border-brand-line bg-brand-paper shadow-lg" aria-label="{{ $chrome->consent->label }}" data-measurement-id="{{ $chrome->consent->measurementId }}" data-script="{{ $chrome->consent->script }}" hidden>
        <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center gap-4 px-6 py-4 text-sm text-brand-ink">
            <p class="site-consent-sentence min-w-64 flex-1">{{ $chrome->consent->sentence }} <a href="{{ $chrome->consent->notice }}" class="font-medium text-brand-primary underline underline-offset-4 hover:text-brand-primary-hover">{{ $chrome->consent->noticeLabel }}</a></p>
            <div class="site-consent-choices grid w-full grid-cols-2 gap-3 sm:w-auto">
                <button type="button" class="site-consent-choice inline-flex w-full items-center justify-center rounded-lg bg-brand-surface px-5 py-2.5 font-semibold text-brand-primary ring-1 ring-brand-primary ring-inset hover:bg-brand-paper focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary" data-consent-choice="granted">{{ $chrome->consent->accept }}</button>
                <button type="button" class="site-consent-choice inline-flex w-full items-center justify-center rounded-lg bg-brand-surface px-5 py-2.5 font-semibold text-brand-primary ring-1 ring-brand-primary ring-inset hover:bg-brand-paper focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary" data-consent-choice="denied">{{ $chrome->consent->reject }}</button>
            </div>
        </div>
    </section>
    @vite('resources/js/site/consent.ts', \App\Http\Middleware\HandleInertiaRequests::BUILD_DIRECTORY)
@endif
