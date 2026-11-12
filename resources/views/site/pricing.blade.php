{{--
    The pricing page, every sentence from pricing.php in the page's language but the plans' names
    and amounts, which are the plan catalogue's as PricingSheet reads them: its heading over a
    lead; the plans' cards in the catalogue's order, each its name, its amount with the line
    saying it is a month's price ex VAT beside it, the trial, what it includes and the signup
    action to $signup; the table of what each plan has, $columns over $rows, both drawn from the
    plans; then the questions about the price, the question's key its anchor. A catalogue that
    cannot be sold from shows the cards with no amount, and one whose plans cannot be named shows
    neither the cards nor the table. The trial's length, $trialDays, fills :count in the lead, the
    cards' trial line and the answers.
--}}
@extends('site.layout')

@section('main')
        <h1 class="max-w-3xl text-4xl font-bold tracking-tight text-balance sm:text-5xl">{{ __('pricing.heading', ['name' => $name]) }}</h1>
        <p class="site-pricing-lead mt-6 max-w-2xl text-lg text-brand-muted">{{ __('pricing.lead', ['name' => $name, 'count' => $trialDays]) }}</p>
@if ($cards !== [])
        <div class="site-pricing-cards mt-12 grid gap-6 sm:grid-cols-2">
@foreach ($cards as $card)
            <section class="site-pricing-card flex flex-col rounded-2xl border border-brand-line bg-brand-paper p-8" id="{{ $card['key'] }}" aria-labelledby="{{ $card['key'] }}-name">
                <h2 id="{{ $card['key'] }}-name" class="text-2xl font-bold tracking-tight">{{ $card['name'] }}</h2>
@if ($card['amount'] !== null)
                <p class="site-pricing-price mt-4"><span class="site-pricing-amount text-4xl font-bold">{{ $card['amount'] }}</span> <span class="site-pricing-period text-brand-muted">{{ __('pricing.card.period') }}</span></p>
@endif
                <p class="site-pricing-trial mt-2 font-medium text-brand-primary">{{ __('pricing.card.trial', ['count' => $trialDays]) }}</p>
                <h3 class="mt-6 text-sm font-semibold text-brand-muted">{{ __('pricing.card.includes') }}</h3>
                <ul class="site-pricing-includes mt-3 grid gap-2">
@foreach ($card['includes'] as $line)
                    <li class="flex items-start gap-3"><span class="mt-2.5 size-2 flex-none rounded-full bg-brand-accent" aria-hidden="true"></span><span>{{ $line }}</span></li>
@endforeach
                </ul>
                <p class="mt-auto pt-8"><a href="{{ $signup }}" class="site-button site-pricing-signup">{{ __('pricing.card.action') }}</a></p>
            </section>
@endforeach
        </div>
        <section class="site-pricing-table mt-20" aria-labelledby="pricing-table-heading">
            <h2 id="pricing-table-heading" class="text-3xl font-bold tracking-tight">{{ __('pricing.table.heading') }}</h2>
            <div class="mt-8 overflow-x-auto">
                <table class="w-full border-collapse text-left">
                    <thead>
                        <tr class="border-b border-brand-line">
                            <th scope="col" class="py-3 pr-4 font-semibold">{{ __('pricing.table.item') }}</th>
@foreach ($columns as $column)
                            <th scope="col" class="px-4 py-3 font-semibold">{{ $column }}</th>
@endforeach
                        </tr>
                    </thead>
                    <tbody>
@foreach ($rows as $row)
                        <tr class="border-b border-brand-line">
                            <th scope="row" class="py-3 pr-4 font-normal">{{ $row['label'] }}</th>
@foreach ($row['cells'] as $cell)
                            <td class="px-4 py-3 text-brand-muted">{{ $cell }}</td>
@endforeach
                        </tr>
@endforeach
                    </tbody>
                </table>
            </div>
        </section>
@endif
        <section class="site-pricing-faq mt-20 max-w-3xl" aria-labelledby="pricing-faq-heading">
            <h2 id="pricing-faq-heading" class="text-3xl font-bold tracking-tight">{{ __('pricing.faq.heading') }}</h2>
            <div class="mt-10 grid gap-10">
@foreach (__('pricing.faq.items', ['name' => $name, 'count' => $trialDays]) as $key => $item)
                <section class="site-pricing-faq-item" id="{{ $key }}" aria-labelledby="{{ $key }}-heading">
                    <h3 id="{{ $key }}-heading" class="text-xl font-semibold">{{ $item['question'] }}</h3>
@foreach ($item['answer'] as $paragraph)
                    <p class="mt-3 text-brand-muted">{{ $paragraph }}</p>
@endforeach
                </section>
@endforeach
            </div>
        </section>
@endsection
