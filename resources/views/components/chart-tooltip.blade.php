<div x-show="tooltip.show" x-cloak class="pointer-events-none absolute z-10 min-w-36 rounded-lg border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-800 px-3 py-2 text-xs shadow-lg" :style="`left: ${tooltip.x}px; top: ${tooltip.y}px`" role="status" aria-live="polite">
    <p class="mb-1 font-medium text-zinc-500 dark:text-zinc-400" x-text="tooltip.title"></p>
    <template x-for="row in tooltip.rows" :key="row.name">
        <div class="flex items-center gap-2 py-0.5" :class="row.kind === 'total' ? 'mt-1 border-t border-zinc-100 dark:border-white/10 pt-1' : ''">
            <span class="h-0.5 w-3 shrink-0 rounded-full" :style="row.slot ? `background: var(--series-${row.slot})` : (row.color ? `background: ${row.color}` : (row.kind === 'target' ? 'background: var(--viz-ink)' : 'background: transparent'))"></span>
            <span class="font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="row.value"></span>
            <span class="text-zinc-500 dark:text-zinc-400" x-text="row.name"></span>
        </div>
    </template>
</div>
