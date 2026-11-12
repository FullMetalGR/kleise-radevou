@extends('decision.layout')

{{--
    What a link gets when there is nothing left to decide: after the decision this page just
    made, after somebody else made it, after the window closed, and on a link pressed twice.

    One page for all four: from the owner's side pressing the same link twice is not a mistake
    worth a stack trace, and an answer that told a replay apart from the decision it replays would
    be saying the second press went wrong when it did not.

    Its words are decision.php's in the request's language: the title read here, the sentences by
    OutcomeWords' keys, read by DecisionPages.
--}}

@section('title', __('decision.title', ['business' => $tenant]))

@section('body')
    <h1>{{ $tenant }}</h1>

    @foreach ($words as $sentence)
        <p>{{ $sentence }}</p>
    @endforeach

    @include('decision.booking')

    <p class="zone">{{ $facts['zone'] }}</p>
@endsection
