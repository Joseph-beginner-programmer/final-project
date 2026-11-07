<x-auth-shell :heading="__('System Login')" :description="__('Sign in to access the management information system')">

    <form method="POST" action="{{ route('login.store') }}" x-data="{ loading: false }" @submit="loading = true" class="space-y-4">
        @csrf

        <div class="{{ $errors->has('email') ? 'motion-safe:animate-shake' : '' }}">
            <label for="email" class="block text-[11px] font-medium tracking-widest uppercase text-zinc-500 dark:text-zinc-400 mb-1.5">
                {{ __('Email') }}
            </label>
            <div class="relative group">
                <svg viewBox="0 0 20 20" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-400 dark:text-zinc-500 transition-colors duration-150 group-has-[:focus]:text-accent" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M3 5.5h14a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.5" />
                    <path d="m3 6 7 5 7-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <input
                    id="email" name="email" type="email" value="{{ old('email') }}"
                    required autofocus autocomplete="username"
                    class="w-full rounded-lg bg-zinc-50 dark:bg-zinc-950 border pl-10 pr-3 py-2.5 text-sm text-zinc-900 dark:text-zinc-50 placeholder:text-zinc-400 dark:placeholder:text-zinc-500
                           focus:outline-none focus:ring-2 focus:ring-offset-0 focus:bg-white dark:focus:bg-zinc-900 transition-all duration-150
                           {{ $errors->has('email') ? 'border-red-400 dark:border-red-500/60 focus:ring-red-500/40 focus:border-red-500' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600 focus:border-accent focus:ring-accent/25' }}"
                />
            </div>
            @error('email')
                <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div x-data="{ show: false, capsOn: false }" class="{{ $errors->has('password') ? 'motion-safe:animate-shake' : '' }}">
            <div class="flex items-center justify-between mb-1.5">
                <label for="password" class="block text-[11px] font-medium tracking-widest uppercase text-zinc-500 dark:text-zinc-400">
                    {{ __('Password') }}
                </label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs text-zinc-500 dark:text-zinc-400 hover:text-accent transition-colors">
                        {{ __('Forgot password?') }}
                    </a>
                @endif
            </div>
            <div class="relative group">
                <svg viewBox="0 0 20 20" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-400 dark:text-zinc-500 transition-colors duration-150 group-has-[:focus]:text-accent" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="4" y="9" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.5" />
                    <path d="M6.5 9V6.5a3.5 3.5 0 0 1 7 0V9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                </svg>
                <input
                    id="password" name="password"
                    :type="show ? 'text' : 'password'"
                    required autocomplete="current-password"
                    @keyup="capsOn = $event.getModifierState ? $event.getModifierState('CapsLock') : false"
                    @blur="capsOn = false"
                    class="w-full rounded-lg bg-zinc-50 dark:bg-zinc-950 border pl-10 pr-10 py-2.5 text-sm text-zinc-900 dark:text-zinc-50
                           focus:outline-none focus:ring-2 focus:ring-offset-0 focus:bg-white dark:focus:bg-zinc-900 transition-all duration-150
                           {{ $errors->has('password') ? 'border-red-400 dark:border-red-500/60 focus:ring-red-500/40 focus:border-red-500' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600 focus:border-accent focus:ring-accent/25' }}"
                />
                <button
                    type="button"
                    @click="show = !show"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-zinc-400 dark:text-zinc-500 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors cursor-pointer"
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
            <p x-show="capsOn" x-cloak class="mt-1.5 flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400">
                <svg viewBox="0 0 20 20" class="w-3.5 h-3.5 shrink-0" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M10 2 18 16H2L10 2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                    <path d="M10 8v3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                    <circle cx="10" cy="13.7" r="0.9" fill="currentColor" />
                </svg>
                {{ __('Caps Lock is on') }}
            </p>
            @error('password')
                <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2 cursor-pointer select-none">
            <input type="checkbox" name="remember"
                   class="h-4 w-4 rounded bg-zinc-50 dark:bg-zinc-950 border-zinc-300 dark:border-zinc-600 text-accent focus:ring-accent focus:ring-offset-0 transition-colors" />
            <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Remember this device') }}</span>
        </label>

        <button type="submit" :disabled="loading"
                class="group w-full flex items-center justify-center gap-2 rounded-lg bg-accent hover:bg-accent/90
                       text-white text-sm font-medium uppercase tracking-wide py-2.5 shadow-sm shadow-accent/20
                       transition-all duration-150 hover:shadow-md hover:shadow-accent/25 hover:-translate-y-0.5 active:translate-y-0
                       disabled:opacity-70 disabled:cursor-not-allowed disabled:hover:translate-y-0 disabled:hover:shadow-sm
                       focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-zinc-900 cursor-pointer">
            <svg x-show="loading" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                <path class="opacity-80" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4Z"></path>
            </svg>
            <span x-text="loading ? '{{ __('Signing in…') }}' : '{{ __('Sign in') }}'"></span>
            <svg x-show="!loading" viewBox="0 0 20 20" class="w-4 h-4 transition-transform duration-150 group-hover:translate-x-0.5" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M4 10h12M12 6l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </form>
</x-auth-shell>
