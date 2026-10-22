@props(['plan' => null, 'hideAmounts' => false])

@php($lines = is_array($plan) && $plan !== [] ? \App\Support\JournalPreview::resolve($plan) : null)
@php($unmapped = collect($lines ?? [])->where('unmapped', true))

<div>
    <p class="mb-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ __('Journal entry to be posted') }}</p>
    @if ($lines === null)
        <p class="rounded-lg border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 px-3 py-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('The journal entry will be calculated when posting.') }}</p>
    @else
        <x-journal-preview :lines="$lines" :hide-amounts="$hideAmounts" />
        @if ($unmapped->isNotEmpty())
            <p class="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-3.5 shrink-0" />
                {{ __('Map these system roles on the Chart of Accounts page before posting: :roles.', ['roles' => $unmapped->pluck('name')->implode(', ')]) }}
            </p>
        @endif
    @endif
</div>
