@props(['heading', 'description' => null, 'title' => null])

<x-layouts.guest :title="$title">

    <style>
        @keyframes fade-slide-up {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .motion-safe\:animate-fade-slide-up {
            animation: fade-slide-up .5s cubic-bezier(.16,1,.3,1) both;
        }
        @keyframes shake {
            10%, 90% { transform: translateX(-1px); }
            20%, 80% { transform: translateX(2px); }
            30%, 50%, 70% { transform: translateX(-4px); }
            40%, 60% { transform: translateX(4px); }
        }
        .motion-safe\:animate-shake {
            animation: shake .4s cubic-bezier(.36,.07,.19,.97) both;
        }
        @media (prefers-reduced-motion: reduce) {
            .motion-safe\:animate-fade-slide-up,
            .motion-safe\:animate-shake { animation: none; }
        }
        [x-cloak] { display: none !important; }
    </style>

    <div class="min-h-screen flex bg-zinc-50 dark:bg-zinc-950">

        <div class="hidden lg:flex lg:w-[46%] relative flex-col items-center justify-center px-12 overflow-hidden bg-zinc-900">

            <div class="absolute inset-0 bg-linear-to-br from-zinc-900 via-zinc-900 to-black"></div>
            <div class="absolute -top-24 -left-16 w-72 h-72 rounded-full opacity-20 blur-3xl" style="background-color: var(--color-accent);"></div>
            <div class="absolute -bottom-32 -right-16 w-80 h-80 rounded-full opacity-[0.15] blur-3xl" style="background-color: var(--color-primary);"></div>
            <div class="absolute inset-0 opacity-[0.05]"
                 style="background-image: linear-gradient(to right, white 1px, transparent 1px), linear-gradient(to bottom, white 1px, transparent 1px); background-size: 40px 40px;"></div>
            <div class="absolute inset-0 bg-linear-to-t from-black/40 via-transparent to-transparent"></div>

            <div class="relative z-10 flex flex-col items-center text-center">

                <div class="relative">
                    <div class="absolute inset-0 rounded-2xl blur-xl opacity-30" style="background-color: var(--color-accent);"></div>
                    <div class="relative w-16 h-16 rounded-2xl border flex items-center justify-center bg-white/5 backdrop-blur-sm shadow-lg"
                         style="border-color: color-mix(in srgb, var(--color-accent) 50%, transparent);">
                        <svg viewBox="0 0 24 24" class="w-8 h-8" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M9 3h6v3.2c0 .6.3 1.1.8 1.5l1.4 1c.5.4.8.9.8 1.5V19a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V10.2c0-.6.3-1.1.8-1.5l1.4-1c.5-.4.8-.9.8-1.5V3Z"
                                  stroke="var(--color-accent)" stroke-width="1.5" />
                            <path d="M8 13h8" stroke="var(--color-accent)" stroke-width="1.5" stroke-dasharray="2 2.5" opacity="0.7" />
                        </svg>
                    </div>
                </div>

                <h1 class="mt-6 font-display text-2xl font-bold tracking-wide text-white uppercase">PT SAI</h1>
                <div class="w-10 h-0.5 mt-3 rounded-full" style="background: linear-gradient(to right, transparent, var(--color-accent), transparent);"></div>

                <p class="mt-5 text-sm text-white/70 max-w-xs leading-relaxed">
                    {{ __('Tracking every product from raw materials to finished goods') }}
                </p>

                <div class="mt-8 inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-3 py-1 backdrop-blur-sm">
                    <svg viewBox="0 0 20 20" class="w-3.5 h-3.5 text-white/50" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 2.5 4.5 4.8v3.9c0 3.4 2.3 6.5 5.5 7.3 3.2-.8 5.5-3.9 5.5-7.3V4.8L10 2.5Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" />
                        <path d="M7.5 10 9.2 11.7 12.5 8.3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span class="text-[10px] tracking-widest uppercase text-white/50">{{ __('Secure System') }}</span>
                </div>
            </div>

            <p class="absolute bottom-8 left-8 text-[10px] tracking-widest uppercase text-white/40">
                PT SAI &copy; {{ date('Y') }} &middot; {{ __('Internal Use Only') }}
            </p>
        </div>

        <div class="lg:hidden absolute top-0 left-0 right-0 bg-linear-to-r from-zinc-900 to-zinc-800 px-6 py-4 z-10 flex items-center gap-3 border-b" style="border-color: color-mix(in srgb, var(--color-accent) 25%, transparent);">
            <div class="w-8 h-8 rounded-lg border flex items-center justify-center bg-white/5" style="border-color: var(--color-accent);">
                <svg viewBox="0 0 24 24" class="w-4 h-4" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M9 3h6v3.2c0 .6.3 1.1.8 1.5l1.4 1c.5.4.8.9.8 1.5V19a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V10.2c0-.6.3-1.1.8-1.5l1.4-1c.5-.4.8-.9.8-1.5V3Z" stroke="var(--color-accent)" stroke-width="1.5" />
                </svg>
            </div>
            <span class="font-display text-sm font-bold tracking-wide text-white uppercase">PT SAI</span>
        </div>

        <div class="relative flex-1 flex flex-col justify-center px-6 py-24 lg:py-12 overflow-hidden">

            <div class="w-full max-w-sm mx-auto relative z-10">

                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm shadow-zinc-900/5 dark:shadow-none p-8 sm:p-9 motion-safe:animate-fade-slide-up">

                    <div class="mb-7">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="text-[10px] font-semibold tracking-widest uppercase text-accent">PT SAI</span>
                            <span class="w-8 h-px bg-zinc-200 dark:bg-zinc-700"></span>
                            <span class="text-[10px] font-semibold tracking-widest uppercase text-zinc-400 dark:text-zinc-500">Sistem Informasi</span>
                        </div>
                        <h2 class="font-display text-lg font-bold uppercase tracking-wide text-zinc-900 dark:text-zinc-50">{{ $heading }}</h2>
                        @if ($description)
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ $description }}</p>
                        @endif
                    </div>

                    @if (session('status'))
                        <div class="mb-4 flex items-start gap-2 rounded-lg border border-green-600/20 dark:border-green-800 bg-green-50 dark:bg-green-900/30 px-3.5 py-2.5 text-sm text-green-700 dark:text-green-400">
                            <svg viewBox="0 0 20 20" class="w-4 h-4 mt-0.5 shrink-0" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z" stroke="currentColor" stroke-width="1.5" />
                                <path d="M7 10.2 9 12l4-4.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span>{{ session('status') }}</span>
                        </div>
                    @endif

                    {{ $slot }}
                </div>

                <p class="mt-5 text-center text-xs text-zinc-400 dark:text-zinc-600 motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
                    {{ __('Need help? Contact your system administrator.') }}
                </p>

                <p class="lg:hidden mt-4 text-center text-[10px] tracking-widest uppercase text-zinc-400 dark:text-zinc-600">
                    PT SAI &copy; {{ date('Y') }} &middot; {{ __('Internal Use Only') }}
                </p>
            </div>
        </div>

    </div>

</x-layouts.guest>
