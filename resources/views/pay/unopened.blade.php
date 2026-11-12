@extends('decision.layout')

{{--
    The pay hop's answer to a provider that could not open the payment page (502), and to a write
    that lost a race on a hold still payable (503). Its sentences are keys of pages.php, read in
    the request's language.
--}}

@section('title', __('pages.pay.unopened.title'))

@section('body')
    <h1>{{ __('pages.pay.unopened.title') }}</h1>
    <p>{{ __('pages.pay.unopened.charged') }}</p>
    <p>{{ __('pages.pay.unopened.again') }}</p>
@endsection
