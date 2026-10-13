@props(['tabs' => [], 'label' => ''])

@php($keys = array_keys($tabs))
<div
    role="tablist"
    aria-label="{{ $label }}"
    x-data="{
        left: 0,
        width: 0,
        keys: @js($keys),
        move() {
            const el = this.$el.querySelector(`[data-tab='${tab}']`);
            if (el) { this.left = el.offsetLeft; this.width = el.offsetWidth; }
        },
        go(step) {
            const i = (this.keys.indexOf(tab) + step + this.keys.length) % this.keys.length;
            tab = this.keys[i];
            this.$nextTick(() => this.$el.querySelector(`[data-tab='${tab}']`)?.focus());
        },
    }"
    x-init="$nextTick(() => move()); $watch('tab', () => $nextTick(() => move()))"
    x-on:resize.window.debounce.100ms="move()"
    x-on:keydown.arrow-right.prevent="go(1)"
    x-on:keydown.arrow-left.prevent="go(-1)"
    {{ $attributes->class('relative flex items-end gap-1 overflow-x-auto border-b border-zinc-200 dark:border-white/10') }}
>
    @foreach ($tabs as $key => $item)
        <button
            type="button"
            role="tab"
            data-tab="{{ $key }}"
            x-on:click="tab = '{{ $key }}'"
            x-bind:aria-selected="tab === '{{ $key }}'"
            x-bind:tabindex="tab === '{{ $key }}' ? 0 : -1"
            x-bind:class="tab === '{{ $key }}' ? 'text-zinc-900 dark:text-white' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200'"
            class="relative shrink-0 inline-flex items-center gap-2 px-3 pb-2.5 pt-1.5 text-xs font-bold uppercase tracking-widest whitespace-nowrap cursor-pointer rounded-t-md transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-accent"
        >
            {{ $item['label'] }}
            @isset($item['count'])
                <span
                    x-bind:class="tab === '{{ $key }}' ? 'bg-accent text-accent-foreground' : 'bg-zinc-100 text-zinc-500 dark:bg-white/10 dark:text-zinc-400'"
                    class="min-w-5 rounded-full px-1.5 py-px text-center font-data text-[10px] font-medium tabular-nums normal-case tracking-normal transition-colors duration-150"
                >{{ $item['count'] }}</span>
            @endisset
            @if (! empty($item['error']))
                <span class="size-1.5 rounded-full bg-red-500" aria-hidden="true"></span>
                <span class="sr-only">{{ __('has errors') }}</span>
            @endif
        </button>
    @endforeach

    <span
        aria-hidden="true"
        class="pointer-events-none absolute bottom-0 left-0 h-0.5 rounded-full bg-accent transition-[transform,width] duration-300 ease-out motion-reduce:transition-none"
        x-bind:style="`transform: translateX(${left}px); width: ${width}px`"
    ></span>
</div>
