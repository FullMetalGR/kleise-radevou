{{--
    The contact page, every sentence from contact.php in the page's language, the public name
    filled in: its heading over a lead, the page's one notice where there is one, the thank-you
    after a message or the limit's refusal at 429, then the form posting to the page's own address
    with the cross-site token. The name, the email address and the message, each a label over its
    field with the refusal named under it and the input kept in it; the trap, a field no person
    fills, hidden by style and from assistive technology, out of the tab order and never filled by
    the browser; while both of Turnstile's keys are set, the widget, drawn by Cloudflare's script
    from its markup inside the form, posting its token in its own field, the check's refusal under
    it. The page reads in full and posts with no script; the widget's is the one script it loads,
    and only while the keys are set.
--}}
@extends('site.layout')

@section('main')
        <h1 class="max-w-3xl text-4xl font-bold tracking-tight text-balance sm:text-5xl">{{ __('contact.heading', ['name' => $name]) }}</h1>
        <p class="site-contact-lead mt-6 max-w-2xl text-lg text-brand-muted">{{ __('contact.lead', ['name' => $name]) }}</p>
@if ($notice !== null)
        <p class="site-contact-notice mt-10 max-w-2xl rounded-lg border border-brand-line bg-brand-paper px-5 py-4 font-medium" role="status">{{ $notice }}</p>
@endif
        <form method="post" action="{{ $action }}" class="site-contact relative mt-10 grid max-w-2xl gap-6">
            @csrf
@foreach (['name' => ['text', 'name', \App\Http\Requests\ContactRequest::NAME_MAX], 'email' => ['email', 'email', \App\Http\Requests\ContactRequest::EMAIL_MAX]] as $field => [$type, $autocomplete, $max])
            <div class="site-contact-field grid gap-2">
                <label for="contact-{{ $field }}" class="site-contact-label font-semibold">{{ __('contact.fields.'.$field) }}</label>
                <input id="contact-{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}" maxlength="{{ $max }}" required value="{{ $input[$field] }}" class="rounded-lg border border-brand-line bg-brand-paper px-4 py-2.5 text-brand-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary" @error($field) aria-invalid="true" aria-describedby="contact-{{ $field }}-error" @enderror>
@error($field)
                <p id="contact-{{ $field }}-error" class="site-contact-error text-sm font-medium text-red-700">{{ $message }}</p>
@enderror
            </div>
@endforeach
            <div class="site-contact-field grid gap-2">
                <label for="contact-message" class="site-contact-label font-semibold">{{ __('contact.fields.message') }}</label>
                <textarea id="contact-message" name="message" rows="8" maxlength="{{ \App\Http\Requests\ContactRequest::MESSAGE_MAX }}" required class="rounded-lg border border-brand-line bg-brand-paper px-4 py-2.5 text-brand-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary" @error('message') aria-invalid="true" aria-describedby="contact-message-error" @enderror>{{ $input['message'] }}</textarea>
@error('message')
                <p id="contact-message-error" class="site-contact-error text-sm font-medium text-red-700">{{ $message }}</p>
@enderror
            </div>
            <div class="site-contact-trap absolute -left-[10000px] top-0 h-px w-px overflow-hidden" aria-hidden="true">
                <label for="contact-{{ $trap }}">{{ __('contact.trap') }}</label>
                <input id="contact-{{ $trap }}" name="{{ $trap }}" type="text" tabindex="-1" autocomplete="off" value="">
            </div>
@if ($turnstile !== null)
            <div class="site-contact-check grid gap-2">
                <div class="cf-turnstile site-contact-turnstile" data-sitekey="{{ $turnstile['siteKey'] }}" data-response-field-name="{{ $token }}" data-language="{{ $language->value }}"></div>
@error($token)
                <p id="contact-turnstile-error" class="site-contact-error text-sm font-medium text-red-700">{{ $message }}</p>
@enderror
            </div>
@endif
            <p><button type="submit" class="site-button site-contact-send">{{ __('contact.send') }}</button></p>
        </form>
@if ($turnstile !== null)
        <script src="{{ $turnstile['script'] }}" async defer></script>
@endif
@endsection
