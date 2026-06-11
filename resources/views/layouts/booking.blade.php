@props([
    'title' => null,
])

{{-- Minimal public layout for the booking flow and the tokened manage page:
     no marketing nav and no app chrome, just a calm centered column with a
     "powered by" footer (specs/pages.md §Public booking). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title])
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-800 antialiased dark:bg-zinc-950 dark:text-zinc-200">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-white">
            {{ __('Skip to content') }}
        </a>

        <main id="main" class="mx-auto w-full max-w-2xl px-4 py-8 sm:px-6 sm:py-12">
            {{ $slot }}
        </main>

        <footer class="mx-auto w-full max-w-2xl px-4 pb-8 sm:px-6">
            <p class="text-center text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Powered by') }}
                <a href="{{ route('home') }}" class="rounded-md font-medium text-zinc-600 underline hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-zinc-300 dark:hover:text-white">
                    {{ config('app.name') }}
                </a>
            </p>
        </footer>

        @fluxScripts
    </body>
</html>
