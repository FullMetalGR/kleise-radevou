{{--
    The central host's 404, in the language of the path's prefix, from site.php, its way home the
    brand's button.
--}}
@extends('site.layout')

@section('main')
        <h1 class="text-3xl font-bold tracking-tight">{{ __('site.not_found.heading') }}</h1>
        <p class="mt-4 text-lg text-brand-muted">{{ __('site.not_found.body') }}</p>
        <p class="mt-8"><a href="{{ $home }}" class="site-button">{{ __('site.not_found.home') }}</a></p>
@endsection
