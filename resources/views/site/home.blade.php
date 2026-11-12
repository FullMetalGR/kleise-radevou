{{--
    The home page, every sentence from home.php in the page's language: the hero, its headline
    over the four claims, the free trial to the signup and a second action to the demo section;
    what the business gets; how it starts in three steps; the verticals strip, $verticals, a link
    to each of the six vertical pages in the page's language; the demo section the second action
    reaches (#demo); and a closing call to the signup. Both signup actions link $signup, the form
    the controller's one helper names for the page's language. The trial's length, $trialDays, fills
    :count in the trial's claim and the closing call.

    The demo section mounts the embed exactly as a tenant pastes it: the mount element with no
    data attribute, its one child the noscript sentence, and the script from the demo's own
    origin, $demo['script'], so the booking it makes is the demo's and every call it makes goes to
    the demo's host, the product itself, which needs no consent. The embed's stylesheet is the
    bundle's own, injected by the script; nothing of the site's styles reaches the mount, which
    carries no class but its own. Under it, a link to the demo's hosted page, $demo['page'].
--}}
@extends('site.layout')

@section('main')
        <section class="site-hero" aria-labelledby="home-hero-heading">
            <h1 id="home-hero-heading" class="max-w-3xl text-4xl font-bold tracking-tight text-balance sm:text-5xl">{{ __('home.heading') }}</h1>
            <ul class="site-hero-claims mt-8 grid max-w-3xl gap-3 text-lg sm:grid-cols-2">
@foreach (['trial', 'commission', 'page', 'guests'] as $claim)
                <li class="flex items-start gap-3"><span class="mt-2.5 size-2 flex-none rounded-full bg-brand-accent" aria-hidden="true"></span><span>{{ __('home.hero.claims.'.$claim, ['count' => $trialDays]) }}</span></li>
@endforeach
            </ul>
            <p class="mt-10 flex flex-wrap gap-4">
                <a href="{{ $signup }}" class="site-button site-home-signup site-hero-start">{{ __('home.hero.start') }}</a>
                <a href="#demo" class="site-button site-button--secondary site-hero-demo">{{ __('home.hero.demo') }}</a>
            </p>
        </section>
        <section class="site-home-gets mt-24" aria-labelledby="home-gets-heading">
            <h2 id="home-gets-heading" class="text-3xl font-bold tracking-tight">{{ __('home.gets.heading') }}</h2>
            <ul class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
@foreach (['page', 'embed', 'calendar', 'emails', 'payments'] as $item)
                <li class="rounded-xl border border-brand-line bg-brand-paper p-6">
                    <h3 class="text-lg font-semibold">{{ __('home.gets.items.'.$item.'.title') }}</h3>
                    <p class="mt-2 text-brand-muted">{{ __('home.gets.items.'.$item.'.body') }}</p>
                </li>
@endforeach
            </ul>
        </section>
        <section class="site-home-steps mt-24" aria-labelledby="home-steps-heading">
            <h2 id="home-steps-heading" class="text-3xl font-bold tracking-tight">{{ __('home.steps.heading') }}</h2>
            <ol class="mt-10 grid gap-8 sm:grid-cols-3">
@foreach (['signup', 'setup', 'share'] as $step)
                <li>
                    <span class="flex size-10 items-center justify-center rounded-full bg-brand-primary font-semibold text-brand-primary-contrast" aria-hidden="true">{{ $loop->iteration }}</span>
                    <h3 class="mt-4 text-lg font-semibold">{{ __('home.steps.items.'.$step.'.title') }}</h3>
                    <p class="mt-2 text-brand-muted">{{ __('home.steps.items.'.$step.'.body') }}</p>
                </li>
@endforeach
            </ol>
        </section>
        <section class="site-home-verticals mt-24" aria-labelledby="home-verticals-heading">
            <h2 id="home-verticals-heading" class="text-3xl font-bold tracking-tight">{{ __('home.verticals.heading') }}</h2>
            <ul class="mt-8 flex flex-wrap gap-3">
@foreach ($verticals as $link)
                <li><a href="{{ $link['href'] }}" class="inline-flex rounded-full border border-brand-line bg-brand-paper px-4 py-2 font-medium text-brand-ink hover:border-brand-primary hover:text-brand-primary">{{ $link['label'] }}</a></li>
@endforeach
            </ul>
        </section>
        <section id="demo" class="site-home-demo mt-24 scroll-mt-8" aria-labelledby="home-demo-heading">
            <h2 id="home-demo-heading" class="text-3xl font-bold tracking-tight">{{ __('home.demo.heading') }}</h2>
            <p class="site-home-demo-body mt-4 max-w-2xl text-lg text-brand-muted">{{ __('home.demo.body') }}</p>
            <div class="site-home-demo-embed mt-8">
                <div class="kr-booking"><noscript>{{ __('home.demo.noscript') }}</noscript></div>
                <script src="{{ $demo['script'] }}" async></script>
            </div>
            <p class="mt-6"><a href="{{ $demo['page'] }}" class="site-link site-home-demo-page">{{ __('home.demo.page') }}</a></p>
        </section>
        <section class="site-home-closing mt-24 rounded-2xl border border-brand-line bg-brand-paper px-6 py-12 text-center sm:px-12" aria-labelledby="home-closing-heading">
            <h2 id="home-closing-heading" class="text-3xl font-bold tracking-tight">{{ __('home.closing.heading', ['count' => $trialDays]) }}</h2>
            <p class="site-home-closing-body mx-auto mt-4 max-w-2xl text-lg text-brand-muted">{{ __('home.closing.body') }}</p>
            <p class="mt-8"><a href="{{ $signup }}" class="site-button site-home-signup">{{ __('home.closing.action') }}</a></p>
        </section>
@endsection
