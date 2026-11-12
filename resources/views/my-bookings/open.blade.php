@extends('decision.layout')

{{--
    The page a My bookings link opens. It renders and spends nothing: mail security scanners fetch
    every link in a message, and a link that opened the session on its fetch would be spent by a
    robot before the customer read the mail. The one button's POST is what opens the session.

    The form names no action, so the browser posts to the address the page was opened at and the
    token is written nowhere in the page. Every sentence is chosen in PHP, by
    MyBookingsController, and read there in the request's language.
--}}

@section('title', $title)

@section('body')
    <h1>{{ $tenant }}</h1>
    <p>{{ $sentence }}</p>

    <form method="post">
        @csrf
        <button type="submit">{{ $label }}</button>
    </form>
@endsection
