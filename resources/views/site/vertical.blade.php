{{--
    The vertical pages' one template, every sentence from the slots under the vertical's key in
    verticals.php, $copy, in the page's language, the public name filled in: the headline over the
    intro; the three benefits, each a title over a sentence; the example services under their
    heading; and the closing line over the signup action to $signup, the form the controller's one
    helper names for the page's language.
--}}
@extends('site.layout')

@section('main')
        <section class="site-vertical-hero" aria-labelledby="vertical-heading">
            <h1 id="vertical-heading" class="max-w-3xl text-4xl font-bold tracking-tight text-balance sm:text-5xl">{{ __($copy.'.heading', ['name' => $name]) }}</h1>
            <p class="site-vertical-intro mt-6 max-w-2xl text-lg text-brand-muted">{{ __($copy.'.intro', ['name' => $name]) }}</p>
        </section>
        <ul class="site-vertical-benefits mt-20 grid gap-6 sm:grid-cols-3">
@foreach (__($copy.'.benefits', ['name' => $name]) as $benefit)
            <li class="rounded-xl border border-brand-line bg-brand-paper p-6">
                <h2 class="text-lg font-semibold">{{ $benefit['title'] }}</h2>
                <p class="mt-2 text-brand-muted">{{ $benefit['body'] }}</p>
            </li>
@endforeach
        </ul>
        <section class="site-vertical-services mt-20" aria-labelledby="vertical-services-heading">
            <h2 id="vertical-services-heading" class="text-3xl font-bold tracking-tight">{{ __($copy.'.services.heading', ['name' => $name]) }}</h2>
            <ul class="mt-8 flex flex-wrap gap-3">
@foreach (__($copy.'.services.items', ['name' => $name]) as $service)
                <li class="rounded-full border border-brand-line bg-brand-paper px-4 py-2">{{ $service }}</li>
@endforeach
            </ul>
        </section>
        <section class="site-vertical-closing mt-24 rounded-2xl border border-brand-line bg-brand-paper px-6 py-12 text-center sm:px-12" aria-labelledby="vertical-closing-line">
            <h2 id="vertical-closing-line" class="site-vertical-closing-line text-3xl font-bold tracking-tight text-balance">{{ __($copy.'.closing.line', ['name' => $name]) }}</h2>
            <p class="mt-8"><a href="{{ $signup }}" class="site-button site-vertical-signup">{{ __($copy.'.closing.action', ['name' => $name]) }}</a></p>
        </section>
@endsection
