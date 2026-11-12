{{--
    The features page, every sentence from features.php in the page's language, the public name
    filled in: its heading over a lead, then the product's features in the file's groups, each a
    heading over its short paragraphs, the group's key its anchor.
--}}
@extends('site.layout')

@section('main')
        <h1 class="max-w-3xl text-4xl font-bold tracking-tight text-balance sm:text-5xl">{{ __('features.heading', ['name' => $name]) }}</h1>
        <p class="site-features-lead mt-6 max-w-2xl text-lg text-brand-muted">{{ __('features.lead', ['name' => $name]) }}</p>
        <div class="mt-16 grid gap-12 sm:grid-cols-2">
@foreach (__('features.groups', ['name' => $name]) as $key => $group)
            <section class="site-features-group" id="{{ $key }}" aria-labelledby="{{ $key }}-heading">
                <h2 id="{{ $key }}-heading" class="text-2xl font-bold tracking-tight">{{ $group['heading'] }}</h2>
@foreach ($group['body'] as $paragraph)
                <p class="mt-3 text-brand-muted">{{ $paragraph }}</p>
@endforeach
            </section>
@endforeach
        </div>
@endsection
