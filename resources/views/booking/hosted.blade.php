@use(App\Http\BusinessPage)
{{--
    The hosted booking page. It renders the mount element, the boot config and the one script tag,
    the same bundle at the same URL the embed snippet names, so the bundle mounts here off the
    inlined config with no request for it. The script sits after the mount element, and the bundle
    waits for DOMContentLoaded anyway when the document is still loading. The theme is one word
    from one column, settings.theme. The body carries it as data-theme for the page chrome, which
    this stylesheet colours through the three tokens it keys on the attribute, the primary, the
    text on it and the ring, neutral's on the body itself; and the mount element carries it again
    because the bundle's stylesheet sets its own tokens on the mount, keyed on the mount's own
    data-theme, which would beat any inherited from the body. Nothing is written inline. The
    noscript sentence is the mount element's only child: the React root replaces the element's
    children on first render, so it goes by itself.

    The header is white under a line, the logo, when the tenant uploaded one, before the name,
    loaded from the tenant's own origin, else a disc in the primary holding the name's first
    letter; the embed never renders either. The logo's alt is empty and the disc is hidden from
    assistive technology, because the name beside it already says who this is. The primary paints
    the disc, the Book now pill and the links that act.

    The mount attributes are the caller's: none for the booking page, and the mode and the
    credentials for the manage page. A null value writes no attribute, because absent and empty
    are the same to every reader of those attributes and an empty credential is the more alarming
    of the two to leave lying in a page.

    The business page, GET / alone, is $business (BusinessPage), in one 1200px column: the
    header's name a link to the top and Book now a pill to the flow; the title block, the page's
    one h1 and the meta line of the open state, the short address and Get directions; the photo
    grid of the cover and the first gallery photos, with a way to the full gallery where it holds
    more; the flow under an anchored heading that is visually hidden, the bundle's own step title
    being what is seen; then the team, the about text, the full gallery, and the hours beside the
    address, the map and the contact pills; then the footer. Each field renders nothing where it
    is empty and no heading stands over nothing. None of it is script: the structured data in the
    head is a data block, and the Book now links are plain anchors. The page's stylesheet holds
    the sticky header and the phone's layout and fixed Book now bar under the one media query;
    the mount's own stylesheet is the bundle's and nothing here reaches into the mount. The bar
    gives way from the first chosen service, while the flow's summary card holds a service and
    is the phone's bottom bar of its own, and on the review and done steps: the rule reads the
    card's class without its empty modifier, or the single-column layout of those two steps,
    and styles nothing inside the mount.

    The cover's alt is empty for the logo's reason, and it is no link; each gallery photo, in the
    grid and in the full gallery, is a link to its full image in the order the row stores them.
    The team's faces are decoration beside the names, so the photo's alt is empty and the
    initials are hidden from assistive technology.

    The business page's header and footer also carry My bookings while the tenant sends the
    bookings link: $myBookings is the page's URL then and null otherwise, and on every other page.

    The manage page passes null and keeps the header of the logo and the name alone, the name its
    h1, and the mount centred at 720px; where a redirect flashed a sentence, $notice is its second
    JSON script beside the config, that sentence alone, escaped as the config is, and null
    otherwise and on every other page. My bookings passes null too and keeps that header; its
    mount names the list mode, and $listed is its second JSON script beside the config, the
    session's bookings or null, the flashed sentence and the form's cross-site token, escaped as
    the config is.

    The lang is $language, the request's language, the prefix of the path else the tenant default,
    the language the inlined config names. Every sentence the page prints is a key of pages.php
    read through the translator in that language, the locale SetTenantLocale set: BusinessPage's
    constants hold the keys, and the noscript sentence is pages.noscript; tenant-authored text,
    the name, About and the team, is printed as typed.
--}}
<!doctype html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tenant->name }}</title>
    <style>
        body { --kr-primary: #171717; --kr-primary-foreground: #fafafa; --kr-ring: #a1a1a1; margin: 0; min-height: 100vh; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: 15px; line-height: 1.5; color: #111827; background: #ffffff; }
        [data-theme="blue"] { --kr-primary: #2563eb; --kr-primary-foreground: #ffffff; --kr-ring: #2563eb; }
        [data-theme="green"] { --kr-primary: #15803d; --kr-primary-foreground: #ffffff; --kr-ring: #15803d; }
        [data-theme="orange"] { --kr-primary: #c2410c; --kr-primary-foreground: #ffffff; --kr-ring: #c2410c; }
        [data-theme="red"] { --kr-primary: #dc2626; --kr-primary-foreground: #ffffff; --kr-ring: #dc2626; }
        [data-theme="rose"] { --kr-primary: #e11d48; --kr-primary-foreground: #ffffff; --kr-ring: #e11d48; }
        [data-theme="violet"] { --kr-primary: #7c3aed; --kr-primary-foreground: #ffffff; --kr-ring: #7c3aed; }
        .hosted-header { display: flex; align-items: center; gap: 16px; box-sizing: border-box; height: 72px; padding: 0 max(40px, calc((100% - 1120px) / 2)); border-bottom: 1px solid #e5e7eb; color: #111827; background: #ffffff; }
        .hosted-header-sticky { position: sticky; top: 0; z-index: 10; }
        .hosted-brand { display: flex; align-items: center; gap: 12px; min-width: 0; color: inherit; text-decoration: none; }
        .hosted-logo { display: block; max-height: 36px; max-width: 160px; }
        .hosted-disc { display: flex; flex-shrink: 0; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; color: var(--kr-primary-foreground); background: var(--kr-primary); font-size: 18px; font-weight: 700; }
        .hosted-name { overflow: hidden; margin: 0; font-size: 18px; font-weight: 700; line-height: 1.25; white-space: nowrap; text-overflow: ellipsis; }
        .hosted-mine { flex-shrink: 0; margin-left: auto; padding: 10px 14px; border: 1px solid #e5e7eb; border-radius: 999px; color: #111827; background: #ffffff; font-weight: 600; text-decoration: none; }
        .hosted-book { flex-shrink: 0; margin-left: auto; padding: 12px 22px; border-radius: 999px; color: var(--kr-primary-foreground); background: var(--kr-primary); font-weight: 600; text-decoration: none; }
        .hosted-mine + .hosted-book { margin-left: 0; }
        .hosted-main { max-width: 720px; margin: 32px auto; padding: 0 40px; }
        .hosted-business .hosted-main { display: flex; flex-direction: column; gap: 56px; box-sizing: border-box; max-width: 1120px; margin: 0 auto; padding: 32px 40px 64px; }
        .hosted-intro { display: flex; flex-direction: column; gap: 24px; }
        .hosted-title { display: flex; flex-direction: column; gap: 10px; }
        .hosted-title h1 { margin: 0; font-size: 40px; font-weight: 800; line-height: 1.15; letter-spacing: -0.02em; }
        .hosted-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0; color: #52606d; font-size: 15px; }
        .hosted-meta a, .hosted-address a { color: var(--kr-primary); font-weight: 600; text-decoration: none; }
        .hosted-status { display: inline-flex; align-items: center; gap: 8px; }
        .hosted-status::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: #d1d5db; }
        .hosted-open { color: #047857; font-weight: 600; }
        .hosted-open::before { background: #047857; }
        .hosted-grid { position: relative; display: grid; grid-template-columns: 2fr 1fr; grid-template-rows: 1fr 1fr; gap: 12px; height: 440px; }
        .hosted-grid > .hosted-cell:first-child { grid-row: 1 / span 2; }
        .hosted-grid-2 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr; }
        .hosted-grid-2 > .hosted-cell:first-child { grid-row: auto; }
        .hosted-grid-1 { display: block; height: auto; }
        .hosted-cell { display: block; overflow: hidden; min-height: 0; border-radius: 16px; background: #f9fafb; }
        .hosted-cell img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .hosted-grid-1 .hosted-cell img { height: auto; aspect-ratio: 21 / 9; max-height: 440px; }
        .hosted-more { position: absolute; right: 16px; bottom: 16px; display: inline-flex; align-items: center; box-sizing: border-box; min-height: 44px; padding: 0 18px; border: 1px solid #d1d5db; border-radius: 999px; color: #111827; background: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; }
        .hosted-flow { position: relative; }
        .hosted-sr { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); clip-path: inset(50%); white-space: nowrap; border: 0; }
        .hosted-flow-title { scroll-margin-top: 96px; }
        .hosted-section { display: flex; flex-direction: column; gap: 20px; }
        .hosted-section h2 { margin: 0; font-size: 28px; font-weight: 800; line-height: 1.2; letter-spacing: -0.01em; }
        .hosted-team { display: flex; flex-wrap: wrap; gap: 24px 32px; margin: 0; padding: 0; list-style: none; }
        .hosted-team li { display: flex; flex-direction: column; align-items: center; gap: 8px; width: 120px; text-align: center; }
        .hosted-face { display: flex; align-items: center; justify-content: center; width: 72px; height: 72px; border-radius: 50%; object-fit: cover; color: var(--kr-primary); background: color-mix(in srgb, var(--kr-primary) 12%, white); font-size: 22px; font-weight: 700; }
        .hosted-team-name { font-size: 14px; font-weight: 600; }
        .hosted-team-title { color: #52606d; font-size: 13px; }
        .hosted-about { max-width: 720px; margin: 0; font-size: 16px; white-space: pre-line; }
        .hosted-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
        .hosted-gallery a { display: block; }
        .hosted-gallery img { display: block; width: 100%; height: 180px; object-fit: cover; border-radius: 14px; }
        .hosted-visit { display: flex; flex-wrap: wrap; gap: 40px; }
        .hosted-visit > .hosted-section { flex: 1 1 320px; gap: 16px; min-width: 0; }
        .hosted-visit > .hosted-section h2 { font-size: 22px; font-weight: 700; }
        .hosted-hours { display: flex; flex-direction: column; gap: 6px; margin: 0; padding: 0; list-style: none; }
        .hosted-hours li { display: flex; align-items: center; gap: 10px; }
        .hosted-hours li::before { content: ""; flex-shrink: 0; width: 8px; height: 8px; border-radius: 50%; background: #047857; }
        .hosted-hours .hosted-closed { color: #52606d; }
        .hosted-hours .hosted-closed::before { background: #d1d5db; }
        .hosted-hours [aria-current] { font-weight: 700; }
        .hosted-day { flex-shrink: 0; width: 120px; }
        .hosted-address { display: grid; justify-items: start; gap: 2px; font-style: normal; }
        .hosted-map { justify-self: stretch; margin-bottom: 12px; }
        .hosted-map img { display: block; width: 100%; height: auto; aspect-ratio: 2 / 1; object-fit: cover; border-radius: 16px; }
        .hosted-address > a:last-child { margin-top: 6px; }
        .hosted-links { display: flex; flex-wrap: wrap; gap: 8px; margin: 0; padding: 0; list-style: none; }
        .hosted-links a { display: inline-flex; align-items: center; box-sizing: border-box; min-height: 44px; padding: 0 18px; border: 1px solid #d1d5db; border-radius: 999px; color: #111827; background: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; }
        .hosted-footer { display: flex; flex-wrap: wrap; gap: 8px 16px; box-sizing: border-box; max-width: 1120px; margin: 0 auto; padding: 24px 40px; border-top: 1px solid #e5e7eb; color: #52606d; font-size: 13px; }
        .hosted-footer a { color: inherit; }
        .hosted-by { margin-left: auto; }
        .hosted-bar { display: none; }
        @media (max-width: 640px) {
            .hosted-header { gap: 10px; height: 60px; padding: 0 16px; }
            .hosted-header-sticky { position: static; }
            .hosted-header .hosted-book { display: none; }
            .hosted-brand { gap: 10px; }
            .hosted-logo { max-height: 32px; max-width: 120px; }
            .hosted-disc { width: 32px; height: 32px; border-radius: 9px; font-size: 16px; }
            .hosted-name { font-size: 16px; }
            .hosted-mine { padding: 8px 0; border: 0; font-size: 14px; }
            .hosted-main { margin: 16px auto; padding: 0 16px; }
            .hosted-business .hosted-main { gap: 40px; padding: 16px 16px 32px; }
            .hosted-intro { gap: 16px; }
            .hosted-title { gap: 6px; }
            .hosted-title h1 { font-size: 26px; line-height: 1.2; }
            .hosted-meta { gap: 4px 10px; font-size: 14px; }
            .hosted-grid { order: -1; display: block; height: auto; }
            .hosted-grid > .hosted-cell:not(:first-child) { display: none; }
            .hosted-grid > .hosted-cell:first-child { height: 180px; border-radius: 14px; }
            .hosted-grid .hosted-cell img { height: 100%; max-height: none; aspect-ratio: auto; }
            .hosted-more { right: 10px; bottom: 10px; min-height: 0; padding: 8px 14px; }
            .hosted-section h2 { font-size: 22px; }
            .hosted-gallery { grid-template-columns: 1fr 1fr; gap: 8px; }
            .hosted-gallery img { height: auto; aspect-ratio: 4 / 3; }
            .hosted-footer { padding: 20px 16px; }
            .hosted-flow-title { scroll-margin-top: 16px; }
            .hosted-bar { display: block; position: fixed; right: 0; bottom: 0; left: 0; z-index: 10; padding: 12px 16px 20px; border-top: 1px solid #e5e7eb; background: #ffffff; box-shadow: 0 -6px 16px rgba(17, 24, 39, 0.06); }
            .hosted-bar a { display: flex; align-items: center; justify-content: center; height: 48px; border-radius: 999px; color: var(--kr-primary-foreground); background: var(--kr-primary); font-weight: 700; text-decoration: none; }
            .hosted-business { padding-bottom: 88px; }
            .hosted-business:has(.kr-booking__estimate:not(.kr-booking__estimate--empty), .kr-booking__layout--single) .hosted-bar { display: none; }
        }
    </style>
@if ($business !== null)
    <script type="application/ld+json">{!! $business['jsonLd'] !!}</script>
@endif
</head>
<body id="top" data-theme="{{ $theme }}" @if ($business !== null)class="hosted-business"@endif>
    <header class="hosted-header{{ $business !== null ? ' hosted-header-sticky' : '' }}">
@if ($business !== null)
        <a class="hosted-brand" href="#top">@if ($logo !== null)<img class="hosted-logo" src="{{ $logo }}" alt="">@else<span class="hosted-disc" aria-hidden="true">{{ $initial }}</span>@endif<span class="hosted-name">{{ $tenant->name }}</span></a>
@else
        <div class="hosted-brand">@if ($logo !== null)<img class="hosted-logo" src="{{ $logo }}" alt="">@else<span class="hosted-disc" aria-hidden="true">{{ $initial }}</span>@endif<h1 class="hosted-name">{{ $tenant->name }}</h1></div>
@endif
        @if ($myBookings !== null)<a class="hosted-mine" href="{{ $myBookings }}">{{ __(BusinessPage::MY_BOOKINGS) }}</a>@endif
        @if ($business !== null)<a class="hosted-book" href="#book">{{ __(BusinessPage::BOOK_NOW) }}</a>@endif
    </header>
    <main class="hosted-main">
@if ($business !== null)
        <section class="hosted-intro">
            <div class="hosted-title">
                <h1>{{ $tenant->name }}</h1>
                @if ($business['meta'] !== [])
                <p class="hosted-meta">@foreach ($business['meta'] as $part)@unless ($loop->first)<span aria-hidden="true">&middot;</span>@endunless @if ($part['href'] !== null)<a href="{{ $part['href'] }}">{{ $part['text'] }}</a>@elseif ($part['open'] !== null)<span class="hosted-status{{ $part['open'] ? ' hosted-open' : '' }}">{{ $part['text'] }}</span>@else<span>{{ $part['text'] }}</span>@endif @endforeach</p>
                @endif
            </div>
            @if ($business['grid'] !== [])
            <div class="hosted-grid hosted-grid-{{ count($business['grid']) }}">
                @foreach ($business['grid'] as $cell)@if ($cell['place'] === null)<div class="hosted-cell"><img class="hosted-cover" src="{{ $cell['photo'] }}" alt=""></div>@else<a class="hosted-cell" href="{{ $cell['photo'] }}"><img src="{{ $cell['photo'] }}" alt="{{ __(BusinessPage::PHOTO, ['n' => (string) $cell['place'], 'count' => (string) count($business['gallery'])]) }}"></a>@endif @endforeach
                @if ($business['more'] !== null)<a class="hosted-more" href="#photos">{{ trans_choice(BusinessPage::SEE_ALL, $business['more']) }}</a>@endif
            </div>
            @endif
        </section>
        <section class="hosted-flow" aria-labelledby="book">
            <h2 class="hosted-flow-title hosted-sr" id="book">{{ __(BusinessPage::FLOW_TITLE) }}</h2>
@endif
        <script type="application/json" id="kr-config">{!! $config !!}</script>
@if ($listed !== null)
        <script type="application/json" id="{{ $listId }}">{!! $listed !!}</script>
@endif
@if ($notice !== null)
        <script type="application/json" id="{{ $noticeId }}">{!! $notice !!}</script>
@endif
        <div class="kr-booking" data-theme="{{ $theme }}"@foreach ($mount as $name => $value)@continue($value === null) {{ $name }}="{{ $value }}"@endforeach><noscript>{{ __('pages.noscript') }}</noscript></div>
        <script src="{{ $embedScript }}" async></script>
@if ($business !== null)
        </section>
        @if ($business['team'] !== [])
        <section class="hosted-section">
            <h2>{{ __(BusinessPage::TEAM) }}</h2>
            <ul class="hosted-team">
                @foreach ($business['team'] as $member)<li>@if ($member['photo'] !== null)<img class="hosted-face" src="{{ $member['photo'] }}" alt="" loading="lazy">@else<span class="hosted-face" aria-hidden="true">{{ $member['initials'] }}</span>@endif <span class="hosted-team-name">{{ $member['name'] }}</span>@if ($member['title'] !== null) <span class="hosted-team-title">{{ $member['title'] }}</span>@endif</li>@endforeach
            </ul>
        </section>
        @endif
        @if ($business['about'] !== null)
        <section class="hosted-section">
            <h2>{{ __(BusinessPage::ABOUT) }}</h2>
            <p class="hosted-about">{{ $business['about'] }}</p>
        </section>
        @endif
        @if ($business['gallery'] !== [])
        <section class="hosted-section" id="photos">
            <h2>{{ __(BusinessPage::PHOTOS) }}</h2>
            <div class="hosted-gallery">
                @foreach ($business['gallery'] as $photo)<a href="{{ $photo }}"><img src="{{ $photo }}" alt="{{ __(BusinessPage::PHOTO, ['n' => (string) $loop->iteration, 'count' => (string) $loop->count]) }}" loading="lazy"></a>@endforeach
            </div>
        </section>
        @endif
        @php($contact = $business['phone'] !== null || $business['email'] !== null || $business['links'] !== [])
        @if ($business['hours'] !== [] || $business['address'] !== [] || $contact)
        <div class="hosted-visit">
            @if ($business['hours'] !== [])
            <section class="hosted-section">
                <h2>{{ __(BusinessPage::HOURS) }}</h2>
                <ul class="hosted-hours">
                    @foreach ($business['hours'] as $row)<li @if (! $row['open'])class="hosted-closed"@endif @if ($row['today'])aria-current="date"@endif><span class="hosted-day">{{ $row['day'] }}</span> <span>{{ $row['times'] }}</span></li>@endforeach
                </ul>
            </section>
            @endif
            @if ($business['address'] !== [] || $contact)
            <section class="hosted-section">
                <h2>{{ __(BusinessPage::FIND_US) }}</h2>
                @if ($business['address'] !== [])
                <address class="hosted-address">
                    @if ($business['map'] !== null)<a class="hosted-map" href="{{ $business['directions'] }}"><img src="{{ $business['map'] }}" alt="{{ __(BusinessPage::MAP) }}" width="640" height="320" loading="lazy"></a>@endif
                    @foreach ($business['address'] as $line)<span>{{ $line }}</span>@endforeach
                    <a href="{{ $business['directions'] }}">{{ __(BusinessPage::DIRECTIONS) }}</a>
                </address>
                @endif
                @if ($contact)
                <ul class="hosted-links">
                    @if ($business['phone'] !== null)<li><a href="{{ $business['tel'] }}">{{ $business['phone'] }}</a></li>@endif
                    @if ($business['email'] !== null)<li><a href="mailto:{{ $business['email'] }}">{{ $business['email'] }}</a></li>@endif
                    @foreach ($business['links'] as $link)<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>@endforeach
                </ul>
                @endif
            </section>
            @endif
        </div>
        @endif
@endif
    </main>
@if ($business !== null)
    <footer class="hosted-footer">
        <span>{{ $tenant->name }}</span>
        @if ($myBookings !== null)<a href="{{ $myBookings }}">{{ __(BusinessPage::MY_BOOKINGS) }}</a>@endif
        <span class="hosted-by">{{ __(BusinessPage::BOOKING_BY, ['name' => $business['site']]) }}</span>
    </footer>
    <nav class="hosted-bar"><a href="#book">{{ __(BusinessPage::BOOK_NOW) }}</a></nav>
@endif
</body>
</html>
