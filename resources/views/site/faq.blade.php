{{--
    The FAQ, every sentence from faq.php in the page's language, the public name filled in: its
    heading over a lead, then the file's questions in its order, each a heading over its answer's
    paragraphs, the question's key its anchor. Every answer is in the HTML as served, and nothing
    folds one away. The trial's length, $trialDays, fills :count where an answer names it.
--}}
@extends('site.layout')

@section('main')
        <h1 class="max-w-3xl text-4xl font-bold tracking-tight text-balance sm:text-5xl">{{ __('faq.heading', ['name' => $name]) }}</h1>
        <p class="site-faq-lead mt-6 max-w-2xl text-lg text-brand-muted">{{ __('faq.lead', ['name' => $name]) }}</p>
        <div class="site-faq mt-16 grid max-w-3xl gap-10">
@foreach (__('faq.items', ['name' => $name, 'count' => $trialDays]) as $key => $item)
            <section class="site-faq-item" id="{{ $key }}" aria-labelledby="{{ $key }}-heading">
                <h2 id="{{ $key }}-heading" class="text-xl font-semibold">{{ $item['question'] }}</h2>
@foreach ($item['answer'] as $paragraph)
                <p class="mt-3 text-brand-muted">{{ $paragraph }}</p>
@endforeach
            </section>
@endforeach
        </div>
@endsection
