{{--
    The legal pages' one template, over the language file $copy names, legal/terms and the rest,
    in the page's language, the public name filled in: while the texts are unreviewed, the draft
    notice as the page's first line; then the heading, the version date and the entity line; then
    the file's sections in its order, each a heading over its blocks, a string a paragraph and a
    list of strings a list, the section's key its anchor.
--}}
@extends('site.layout')

@section('main')
@if ($draft)
        <p class="site-legal-draft mb-10 rounded-lg border border-brand-accent bg-brand-paper px-4 py-3 font-medium" role="note">{{ __('site.legal.draft') }}</p>
@endif
        <article class="site-legal max-w-3xl" aria-labelledby="legal-heading">
            <h1 id="legal-heading" class="text-4xl font-bold tracking-tight text-balance">{{ __($copy.'.heading', ['name' => $name]) }}</h1>
            <p class="site-legal-version mt-4 text-brand-muted">{{ __('site.legal.version', ['date' => __($copy.'.version', ['name' => $name])]) }}</p>
            <p class="site-legal-entity mt-1 text-sm text-brand-muted">{{ $entity }}</p>
@foreach (__($copy.'.sections', ['name' => $name]) as $key => $section)
            <section class="site-legal-section mt-12" id="{{ $key }}" aria-labelledby="{{ $key }}-heading">
                <h2 id="{{ $key }}-heading" class="text-2xl font-bold tracking-tight">{{ $section['heading'] }}</h2>
@foreach ($section['body'] as $block)
@if (is_array($block))
                <ul class="mt-4 list-disc space-y-2 pl-6">
@foreach ($block as $item)
                    <li>{{ $item }}</li>
@endforeach
                </ul>
@else
                <p class="mt-4">{{ $block }}</p>
@endif
@endforeach
            </section>
@endforeach
        </article>
@endsection
