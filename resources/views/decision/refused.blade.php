@extends('decision.layout')

{{--
    The one body every refused decision link gets: a signature that does not verify, a link
    minted for another subdomain, a user who is not of this tenant, a role that may not decide,
    and a staff member with nothing on the booking.

    It names no booking, no customer, no decision and no tenant. A refusal that varied would
    answer questions to somebody who has already been refused: whether a booking exists, who
    works here, and which fact changed under a stale link.

    Its words are decision.php's in the request's language, the prefix the link was opened under;
    the title is the heading's own line.
--}}

@section('title', __('decision.refused.title'))

@section('body')
    <h1>{{ __('decision.refused.title') }}</h1>
    <p>{{ __('decision.refused.changed') }}</p>
    <p>{{ __('decision.refused.again') }}</p>
@endsection
