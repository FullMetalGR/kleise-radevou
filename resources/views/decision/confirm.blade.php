@extends('decision.layout')

{{--
    The page a live decision link opens. It renders and decides nothing: the link is inert because
    mail security scanners follow every link in a message before it reaches the inbox, and a link
    that decided on GET would have every approval decided by a robot, silently, before the owner
    read the email.

    One button, for the one decision the link carries. The form posts back to a link minted
    again, and the POST is what decides.

    Every value is built in PHP, by DecisionWords, which is where the word on the button, the
    sentence under it and whether there is a box to say why are all decided, and DecisionPages,
    which reads them in the page's language. The page's own words are decision.php's, read here in
    the same language, the request's.
--}}

@section('title', __('decision.title', ['business' => $tenant]))

@section('body')
    <h1>{{ $tenant }}</h1>
    <p>{{ __('decision.confirm.waiting') }} {{ $decision['consequence'] }}</p>

    @include('decision.booking')

    <form method="post" action="{{ $action }}">
        @csrf

        @if ($decision['reason'])
            <p>
                <label for="reason">{{ __('decision.confirm.reason') }}</label>
            </p>
            <p>
                <textarea id="reason" name="reason"></textarea>
            </p>
        @endif

        <button type="submit">{{ $decision['verb'] }}</button>
    </form>

    <p class="zone">{{ $facts['zone'] }}</p>
@endsection
