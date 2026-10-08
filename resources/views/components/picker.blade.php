{{--
    Searchable listbox replacing a native <select>: options can show a title, a code/subtitle and
    a right-hand detail, and unavailable options say *why*. Used for workers (Work Order) and
    products (Purchase Order line items).

    The panel is absolutely positioned under the trigger (or above it when there's no room below), so
    it must not sit inside an `overflow: hidden/auto` container — the pages using it render their rows
    as grids for exactly that reason.

    Props:
      model          Livewire property path to set, e.g. "items.0.product_id"
      options        list of ['id', 'title', 'subtitle', 'meta', 'disabled_reason'] (meta / disabled_reason nullable)
      selected       currently selected id (string, '' when empty)
      placeholder    trigger text when nothing is selected
      label          accessible name for the trigger + listbox
      invalid        true when the field has a validation error (red border)
      size           'sm' = h-8, matches flux:input size="sm" (table rows); 'md' = h-10, matches a default flux:input
--}}
@props([
    'model',
    'options' => [],
    'selected' => '',
    'placeholder' => '',
    'label' => '',
    'invalid' => false,
    'size' => 'sm',
])

@php
    $current = collect($options)->firstWhere('id', (int) $selected);
    $searchables = collect($options)->map(fn ($o) => mb_strtolower($o['title'].' '.$o['subtitle']))->values()->all();
@endphp

<div
    x-data="{
        open: false,
        q: '',
        items: @js($searchables),
        up: false,
        match(text) { return this.q === '' || text.includes(this.q.toLowerCase()) },
        get none() { return ! this.items.some(text => this.match(text)) },
        show() {
            this.open = true;
            this.q = '';
            this.$nextTick(() => {
                // open upward when there isn't room below the trigger (but there is above)
                const r = this.$refs.trigger.getBoundingClientRect();
                const h = this.$refs.panel.offsetHeight;
                this.up = r.bottom + h + 8 > window.innerHeight && r.top - h - 8 > 0;
                this.$refs.search.focus();
            });
        },
        close(refocus = true) { this.open = false; if (refocus) this.$refs.trigger.focus() },
    }"
    x-on:keydown.escape.stop="close()"
    class="relative"
>
    <button
        type="button"
        x-ref="trigger"
        x-on:click="open ? close(false) : show()"
        x-on:keydown.down.prevent="show()"
        aria-haspopup="listbox"
        :aria-expanded="open"
        aria-label="{{ $label }}"
        wire:loading.attr="disabled"
        wire:target="{{ $model }}"
        @class([
            'w-full flex items-center justify-between gap-2 rounded-lg border bg-white dark:bg-white/5 text-left shadow-xs transition-colors cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/50 hover:border-zinc-300 dark:hover:border-white/20',
            // heights mirror flux:input exactly, so a picker lines up with the input beside it
            'h-8 px-2.5' => $size === 'sm',
            'h-10 px-3' => $size === 'md',
            'border-red-500' => $invalid,
            'border-zinc-200 dark:border-white/10' => ! $invalid,
        ])
    >
        @if ($current)
            {{-- name only once chosen; the code is shown in the list, where it helps choosing --}}
            <span class="truncate text-sm text-zinc-900 dark:text-white" title="{{ $current['title'] }} · {{ $current['subtitle'] }}">{{ $current['title'] }}</span>
        @else
            <span class="truncate text-sm text-zinc-400 dark:text-zinc-500">{{ $placeholder }}</span>
        @endif

        <span class="shrink-0 flex items-center">
            <flux:icon.loading wire:loading wire:target="{{ $model }}" variant="micro" class="size-4 text-accent" />
            <flux:icon.chevron-up-down wire:loading.remove wire:target="{{ $model }}" variant="micro" class="size-4 text-zinc-400" />
        </span>
    </button>

    {{-- A plain absolute panel inside the component. (Teleporting it to <body> was tried and dropped:
         Livewire re-syncs the teleported copy with server HTML on every update, wiping the inline
         styles Alpine uses to hide/position it.) Nothing around these pickers clips overflow. --}}
    <div
        x-ref="panel"
        x-show="open"
        :class="up ? 'bottom-full mb-1.5' : 'top-full mt-1.5'"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-on:click.outside="close(false)"
        x-on:keydown.down.prevent="$focus.within($refs.panel).wrap().next()"
        x-on:keydown.up.prevent="$focus.within($refs.panel).wrap().previous()"
        x-cloak
        class="absolute left-0 z-50 w-full min-w-64 rounded-lg border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40"
    >
        <div class="p-1.5 border-b border-zinc-100 dark:border-white/10">
            <div class="relative">
                <flux:icon.magnifying-glass variant="micro" class="absolute left-2 top-1/2 -translate-y-1/2 size-3.5 text-zinc-400 pointer-events-none" />
                <input
                    x-ref="search"
                    x-model="q"
                    type="text"
                    placeholder="{{ __('Search name or code...') }}"
                    aria-label="{{ __('Search name or code...') }}"
                    class="w-full h-8 rounded-md bg-zinc-50 dark:bg-white/5 pl-7 pr-2 text-sm text-zinc-900 dark:text-white placeholder:text-zinc-400 border-0 focus:outline-none focus:ring-2 focus:ring-accent/40"
                />
            </div>
        </div>

        <div role="listbox" aria-label="{{ $label }}" class="max-h-64 overflow-y-auto p-1.5 space-y-0.5">
            @foreach ($options as $i => $option)
                @php
                    $isSelected = (int) $selected === $option['id'];
                    $reason = $isSelected ? null : ($option['disabled_reason'] ?? null);
                @endphp
                <button
                    type="button"
                    role="option"
                    aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                    @if ($reason) disabled aria-disabled="true" @endif
                    x-show="match(items[{{ $i }}])"
                    wire:key="{{ $model }}-opt-{{ $option['id'] }}"
                    @unless ($reason)
                        wire:click="$set('{{ $model }}', '{{ $option['id'] }}')"
                        x-on:click="close()"
                    @endunless
                    @class([
                        'w-full flex items-center gap-3 rounded-md px-2.5 py-1.5 text-left transition-colors focus:outline-none',
                        'cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/5 focus-visible:bg-accent/10' => ! $reason,
                        'cursor-not-allowed opacity-50' => $reason,
                        'bg-accent/5 dark:bg-accent/10' => $isSelected,
                    ])
                >
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate text-sm text-zinc-900 dark:text-white">{{ $option['title'] }}</span>
                        <span class="font-data text-[11px] text-accent">{{ $option['subtitle'] }}</span>
                    </span>

                    @if ($reason)
                        <span class="shrink-0 text-[11px] text-zinc-500 dark:text-zinc-400">{{ $reason }}</span>
                    @elseif (filled($option['meta'] ?? null))
                        <span class="shrink-0 font-data text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400">{{ $option['meta'] }}</span>
                    @endif

                    @if ($isSelected)
                        <flux:icon.check variant="micro" class="size-4 shrink-0 text-accent" />
                    @endif
                </button>
            @endforeach

            <p x-show="none" x-cloak class="px-3 py-4 text-center text-xs text-zinc-500 dark:text-zinc-400">{{ __('Nothing matches your search.') }}</p>
        </div>
    </div>
</div>
