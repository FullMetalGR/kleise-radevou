@extends('decision.layout')

{{--
    The payment link's status page: what the approved message's link opens and where the provider
    brings the customer back. It renders and decides nothing, because mail security scanners fetch
    every link in a message; the button's POST is the hop. It names the business and the booking's
    status, and nothing about the customer.

    Every sentence is chosen in PHP: the page's own by PayPages::words, and above them, once, the
    sentence the pay hop's redirect flashed, where one came, each read in the request's language;
    the button's word is a key of pages.php read here.
--}}

@section('title', $title)

@section('body')
    <h1>{{ $title }}</h1>
    <p>{{ $tenant }}</p>

    @if ($notice !== null)
        <p role="status">{{ $notice }}</p>
    @endif

    @foreach ($lines as $line)
        <p>{{ $line }}</p>
    @endforeach

    @if ($action !== null)
        <form method="post" action="{{ $action }}">
            @csrf
            <button type="submit">{{ __('pages.pay.pay_now') }}</button>
        </form>
    @endif
@endsection
