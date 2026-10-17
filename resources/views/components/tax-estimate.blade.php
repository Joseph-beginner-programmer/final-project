@props(['amount' => '0'])

@php
    $estimate = \App\Support\TaxEstimate::for((string) $amount);
    $rupiah = fn (string $v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $rate = str_contains($estimate['rate'], '.') ? rtrim(rtrim($estimate['rate'], '0'), '.') : $estimate['rate'];
@endphp

<dl {{ $attributes->class('mt-2.5 space-y-0.5 border-t border-accent-foreground/20 pt-2 text-[11px] text-accent-foreground/80') }}
    title="{{ __('Estimate only — the actual tax is set on the invoice.') }}">
    <div class="flex items-center justify-between gap-3">
        <dt>{{ __('Est. VAT :rate%', ['rate' => $rate]) }}</dt>
        <dd class="font-data tabular-nums">+{{ $rupiah($estimate['tax']) }}</dd>
    </div>
    <div class="flex items-center justify-between gap-3">
        <dt>{{ __('Est. incl. VAT') }}</dt>
        <dd class="font-data font-medium tabular-nums text-accent-foreground">{{ $rupiah($estimate['total']) }}</dd>
    </div>
    <span class="sr-only">{{ __('Estimate only — the actual tax is set on the invoice.') }}</span>
</dl>
