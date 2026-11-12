@extends('decision.layout')

{{--
    The pay hop's answer to a hold that lapsed, was released or was cancelled. Its sentences are
    keys of pages.php, read in the request's language.
--}}

@section('title', __('pages.pay.ended.title'))

@section('body')
    <h1>{{ __('pages.pay.ended.title') }}</h1>
    <p>{{ __('pages.pay.ended.given_back') }}</p>
    <p>{{ __('pages.pay.ended.again') }}</p>
@endsection
