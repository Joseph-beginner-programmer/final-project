<x-layouts::guest>

    <style>
        @keyframes fade-slide-up {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .motion-safe\:animate-fade-slide-up {
            animation: fade-slide-up .5s cubic-bezier(.16,1,.3,1) both;
        }
        @media (prefers-reduced-motion: reduce) {
            .motion-safe\:animate-fade-slide-up { animation: none; }
        }
    </style>

    <div class="min-h-screen flex bg-white">

        {{-- Left panel: brand identity --}}
        <div class="hidden lg:flex lg:w-[46%] relative flex-col items-center justify-center px-12 overflow-hidden bg-zinc-900">

            {{-- Layered depth: base gradient + soft glows + fine grid texture --}}
            <div class="absolute inset-0 bg-linear-to-br from-zinc-900 via-zinc-900 to-black"></div>
            <div class="absolute -top-24 -left-16 w-72 h-72 rounded-full opacity-20 blur-3xl" style="background-color: var(--color-accent);"></div>
            <div class="absolute -bottom-32 -right-16 w-80 h-80 rounded-full opacity-[0.15] blur-3xl" style="background-color: var(--color-primary);"></div>
            <div class="absolute inset-0 opacity-[0.05]"
                 style="background-image: linear-gradient(to right, white 1px, transparent 1px), linear-gradient(to bottom, white 1px, transparent 1px); background-size: 40px 40px;"></div>
            <div class="absolute inset-0 bg-linear-to-t from-black/40 via-transparent to-transparent"></div>

            <div class="relative z-10 flex flex-col items-center text-center">

                {{-- Glass badge icon with soft glow ring --}}
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

        {{-- Mobile brand strip --}}
        <div class="lg:hidden absolute top-0 left-0 right-0 bg-linear-to-r from-zinc-900 to-zinc-800 px-6 py-4 z-10 flex items-center gap-3 border-b" style="border-color: color-mix(in srgb, var(--color-accent) 25%, transparent);">
            <div class="w-8 h-8 rounded-lg border flex items-center justify-center bg-white/5" style="border-color: var(--color-accent);">
                <svg viewBox="0 0 24 24" class="w-4 h-4" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M9 3h6v3.2c0 .6.3 1.1.8 1.5l1.4 1c.5.4.8.9.8 1.5V19a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V10.2c0-.6.3-1.1.8-1.5l1.4-1c.5-.4.8-.9.8-1.5V3Z" stroke="var(--color-accent)" stroke-width="1.5" />
                </svg>
            </div>
            <span class="font-display text-sm font-bold tracking-wide text-white uppercase">PT SAI</span>
        </div>

        {{-- Right panel: login form --}}
        <div class="flex-1 flex flex-col justify-center px-6 py-24 lg:py-12">
            <div class="w-full max-w-sm mx-auto">

                <div class="mb-7 motion-safe:animate-fade-slide-up">
                    <h2 class="font-display text-lg font-bold uppercase tracking-wide text-zinc-900">{{ __('System Login') }}</h2>
                    <p class="text-sm text-zinc-500 mt-1">{{ __('Sign in to access the management information system') }}</p>
                </div>

                @if (session('status'))
                    <div class="mb-4 flex items-start gap-2 rounded-lg border border-green-600/20 bg-green-50 px-3.5 py-2.5 text-sm text-green-700 motion-safe:animate-fade-slide-up">
                        <svg viewBox="0 0 20 20" class="w-4 h-4 mt-0.5 shrink-0" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z" stroke="currentColor" stroke-width="1.5" />
                            <path d="M7 10.2 9 12l4-4.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span>{{ session('status') }}</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="space-y-4 motion-safe:animate-fade-slide-up" style="animation-delay: 60ms;">
                    @csrf

                    <div>
                        <label for="email" class="block text-[11px] font-medium tracking-widest uppercase text-zinc-500 mb-1.5">
                            {{ __('Email') }}
                        </label>
                        <div class="relative">
                            <svg viewBox="0 0 20 20" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-400" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M3 5.5h14a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.5" />
                                <path d="m3 6 7 5 7-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <input
                                id="email" name="email" type="email" value="{{ old('email') }}"
                                required autofocus autocomplete="username"
                                class="w-full rounded-lg bg-zinc-50 border pl-10 pr-3 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400
                                       focus:outline-none focus:ring-2 focus:ring-offset-0 focus:bg-white transition-all duration-150
                                       {{ $errors->has('email') ? 'border-red-400 focus:ring-red-500/40 focus:border-red-500' : 'border-zinc-200 hover:border-zinc-300 focus:border-accent focus:ring-accent/25' }}"
                            />
                        </div>
                        @error('email')
                            <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div x-data="{ show: false }">
                        <div class="flex items-center justify-between mb-1.5">
                            <label for="password" class="block text-[11px] font-medium tracking-widest uppercase text-zinc-500">
                                {{ __('Password') }}
                            </label>
                            @if (Route::has('password.request'))
                                <a href="{{ route('password.request') }}" class="text-xs text-zinc-500 hover:text-accent transition-colors">
                                    {{ __('Forgot password?') }}
                                </a>
                            @endif
                        </div>
                        <div class="relative">
                            <svg viewBox="0 0 20 20" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-400" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="4" y="9" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.5" />
                                <path d="M6.5 9V6.5a3.5 3.5 0 0 1 7 0V9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                            </svg>
                            <input
                                id="password" name="password"
                                :type="show ? 'text' : 'password'"
                                required autocomplete="current-password"
                                class="w-full rounded-lg bg-zinc-50 border pl-10 pr-10 py-2.5 text-sm text-zinc-900
                                       focus:outline-none focus:ring-2 focus:ring-offset-0 focus:bg-white transition-all duration-150
                                       {{ $errors->has('password') ? 'border-red-400 focus:ring-red-500/40 focus:border-red-500' : 'border-zinc-200 hover:border-zinc-300 focus:border-accent focus:ring-accent/25' }}"
                            />
                            <button
                                type="button"
                                @click="show = !show"
                                class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-zinc-400 hover:text-zinc-600 transition-colors cursor-pointer"
                                :aria-label="show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                            >
                                <svg x-show="!show" viewBox="0 0 20 20" class="w-4 h-4" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M1.5 10S4.5 4.5 10 4.5 18.5 10 18.5 10 15.5 15.5 10 15.5 1.5 10 1.5 10Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                    <circle cx="10" cy="10" r="2.25" stroke="currentColor" stroke-width="1.5" />
                                </svg>
                                <svg x-show="show" viewBox="0 0 20 20" class="w-4 h-4" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: none;">
                                    <path d="M2.5 2.5 17.5 17.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                                    <path d="M8.3 5.1c.55-.1 1.12-.15 1.7-.15 5.5 0 8.5 5.5 8.5 5.5a13.4 13.4 0 0 1-2.9 3.6M5.6 6.4C3.4 7.9 1.5 10 1.5 10s3 5.5 8.5 5.5c1.1 0 2.1-.2 3-.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                        @error('password')
                            <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" name="remember"
                               class="h-4 w-4 rounded bg-white border-zinc-300 text-accent focus:ring-accent focus:ring-offset-0 transition-colors" />
                        <span class="text-sm text-zinc-600">{{ __('Remember this device') }}</span>
                    </label>

                    <button type="submit"
                            class="group w-full flex items-center justify-center gap-2 rounded-lg bg-accent hover:bg-accent/90
                                   text-white text-sm font-medium uppercase tracking-wide py-2.5 shadow-sm shadow-accent/20
                                   transition-all duration-150 hover:shadow-md hover:shadow-accent/25 hover:-translate-y-0.5 active:translate-y-0
                                   focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 focus:ring-offset-white cursor-pointer">
                        {{ __('Sign in') }}
                        <svg viewBox="0 0 20 20" class="w-4 h-4 transition-transform duration-150 group-hover:translate-x-0.5" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M4 10h12M12 6l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </form>

                <p class="mt-5 text-center text-xs text-zinc-400 motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
                    {{ __('Need help? Contact your system administrator.') }}
                </p>
            </div>
        </div>

    </div>

</x-layouts::guest>
