@props([
    'title' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title])
        <meta name="description" content="LibreNexus is a free, MIT-licensed appointment scheduling system for small offices. Staff, services, availability, and a public booking page in minutes.">
    </head>
    <body class="min-h-screen bg-white text-zinc-800 antialiased dark:bg-zinc-950 dark:text-zinc-200">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-white">
            Skip to content
        </a>

        <header class="border-b border-zinc-200 dark:border-zinc-800">
            <nav aria-label="Main" class="mx-auto flex h-16 w-full max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <a href="{{ route('home') }}" class="flex items-center gap-2 rounded-md font-semibold text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-white">
                    <x-app-logo-icon class="size-6 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    {{ config('app.name') }}
                </a>

                <div class="hidden items-center gap-6 text-sm font-medium text-zinc-600 md:flex dark:text-zinc-300">
                    <a href="{{ route('pricing') }}" class="rounded-md hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:hover:text-white">Pricing</a>
                    <a href="{{ route('docs') }}" class="rounded-md hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:hover:text-white">Docs</a>
                    <a href="{{ route('open-source') }}" class="rounded-md hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:hover:text-white">Open source</a>
                </div>

                <div class="flex items-center gap-3">
                    <a href="{{ route('login') }}" class="rounded-md px-2 py-1.5 text-sm font-medium text-zinc-600 hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-zinc-300 dark:hover:text-white">
                        Log in
                    </a>
                    <a href="{{ route('register') }}" class="rounded-md bg-brand-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-brand-700 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600">
                        Sign up
                    </a>
                </div>
            </nav>
        </header>

        <main id="main">
            {{ $slot }}
        </main>

        <footer class="border-t border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mx-auto w-full max-w-6xl px-4 py-12 sm:px-6">
                <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="space-y-3">
                        <p class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-white">
                            <x-app-logo-icon class="size-5 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                            {{ config('app.name') }}
                        </p>
                        <p class="max-w-xs text-sm text-zinc-600 dark:text-zinc-400">
                            Free appointment scheduling for small offices. MIT licensed, open source, self-hostable.
                        </p>
                    </div>

                    <nav aria-label="Product" class="space-y-3 text-sm">
                        <p class="font-medium text-zinc-900 dark:text-white">Product</p>
                        <ul class="space-y-2">
                            <li><a href="{{ route('pricing') }}" class="rounded-md text-zinc-600 hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-zinc-400 dark:hover:text-white">Pricing</a></li>
                            <li><a href="{{ route('docs') }}" class="rounded-md text-zinc-600 hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-zinc-400 dark:hover:text-white">Documentation</a></li>
                            <li><a href="{{ route('register') }}" class="rounded-md text-zinc-600 hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-zinc-400 dark:hover:text-white">Sign up</a></li>
                        </ul>
                    </nav>

                    <nav aria-label="Project" class="space-y-3 text-sm">
                        <p class="font-medium text-zinc-900 dark:text-white">Project</p>
                        <ul class="space-y-2">
                            <li><a href="{{ route('open-source') }}" class="rounded-md text-zinc-600 hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-zinc-400 dark:hover:text-white">Open source</a></li>
                            <li><a href="{{ config('app.repository_url') }}" rel="noopener" class="rounded-md text-zinc-600 hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-zinc-400 dark:hover:text-white">GitHub repository</a></li>
                        </ul>
                    </nav>

                    <nav aria-label="Legal" class="space-y-3 text-sm">
                        <p class="font-medium text-zinc-900 dark:text-white">Legal</p>
                        <ul class="space-y-2">
                            <li><a href="{{ route('privacy') }}" class="rounded-md text-zinc-600 hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-zinc-400 dark:hover:text-white">Privacy</a></li>
                            <li><a href="{{ route('imprint') }}" class="rounded-md text-zinc-600 hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-zinc-400 dark:hover:text-white">Imprint</a></li>
                        </ul>
                    </nav>
                </div>

                <p class="mt-10 border-t border-zinc-200 pt-6 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    {{ config('app.name') }} is free software, MIT licensed.
                </p>
            </div>
        </footer>
    </body>
</html>
