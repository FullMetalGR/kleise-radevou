{{--
    The booking as both decision pages print it: what was booked and when, who it is for, and
    what it came to. One partial, so the page an owner decides on and the page they get back
    afterwards describe the same booking in the same words.

    Every value is built in PHP, by BookingFacts, which decides whether the customer's email and
    phone are in the list at all, each label and value in the page's language.
--}}
<ul>
    @foreach ($facts['items'] as $item)
        <li>
            <div>{{ $item['what'] }}</div>
            <div class="when">{{ $item['when'] }}</div>
        </li>
    @endforeach
</ul>

<dl>
    @foreach ($facts['customer'] as $fact)
        <dt>{{ $fact['label'] }}</dt>
        <dd>{{ $fact['value'] }}</dd>
    @endforeach
</dl>

<p class="total">{{ $facts['total'] }}</p>
