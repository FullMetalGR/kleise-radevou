@extends('decision.layout')

{{--
    The pay hop's answer to a hold too short for any payment page the provider would open, after a
    move cleared the one an earlier press opened (409): it can no longer be paid online, and
    saying "try again" would be untrue, since the hold only gets shorter. Its sentences are keys
    of pages.php, read in the request's language.
--}}

@section('title', __('pages.pay.closed.title'))

@section('body')
    <h1>{{ __('pages.pay.closed.title') }}</h1>
    <p>{{ __('pages.pay.closed.held') }}</p>
    <p>{{ __('pages.pay.closed.given_back') }}</p>
@endsection
