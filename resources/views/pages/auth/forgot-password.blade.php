<x-auth-shell :title="__('Forgot password')" :heading="__('Forgot password')" :description="__('Enter your email to receive a password reset link')">

    <form method="POST" action="{{ route('password.email') }}" x-data="{ loading: false }" @submit="loading = true" class="space-y-4">
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
                    required autofocus autocomplete="username" placeholder="email@example.com"
                    class="w-full rounded-lg bg-zinc-50 dark:bg-zinc-950 border pl-10 pr-3 py-2.5 text-sm text-zinc-900 dark:text-zinc-50 placeholder:text-zinc-400 dark:placeholder:text-zinc-500
                           focus:outline-none focus:ring-2 focus:ring-offset-0 focus:bg-white dark:focus:bg-zinc-900 transition-all duration-150
                           {{ $errors->has('email') ? 'border-red-400 dark:border-red-500/60 focus:ring-red-500/40 focus:border-red-500' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600 focus:border-accent focus:ring-accent/25' }}"
                />
            </div>
            @error('email')
                <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" :disabled="loading"
                class="group w-full flex items-center justify-center gap-2 rounded-lg bg-accent hover:bg-accent/90 px-4 text-center
                       text-white text-sm font-medium uppercase tracking-wide py-2.5 shadow-sm shadow-accent/20
                       transition-all duration-150 hover:shadow-md hover:shadow-accent/25 hover:-translate-y-0.5 active:translate-y-0
                       disabled:opacity-70 disabled:cursor-not-allowed disabled:hover:translate-y-0 disabled:hover:shadow-sm
                       focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-zinc-900 cursor-pointer">
            <svg x-show="loading" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                <path class="opacity-80" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4Z"></path>
            </svg>
            <span x-text="loading ? '{{ __('Sending…') }}' : '{{ __('Email password reset link') }}'"></span>
        </button>
    </form>

    <a href="{{ route('login') }}" class="mt-6 flex items-center justify-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400 hover:text-accent transition-colors">
        <svg viewBox="0 0 20 20" class="w-3.5 h-3.5" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M16 10H4M8 6l-4 4 4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        {{ __('Back to login') }}
    </a>

</x-auth-shell>
