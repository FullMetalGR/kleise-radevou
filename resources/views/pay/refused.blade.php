@extends('decision.layout')

{{--
    The manage guard's refusal on the pay hop: one body for every way a link fails. Its sentences
    are keys of pages.php, read in the request's language.
--}}

@section('title', __('pages.pay.refused.title'))

@section('body')
    <h1>{{ __('pages.pay.refused.title') }}</h1>
    <p>{{ __('pages.pay.refused.sentence') }}</p>
@endsection
