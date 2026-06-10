<x-layouts.public title="Pricing">
    <section class="mx-auto w-full max-w-3xl px-4 py-16 text-center sm:px-6">
        <h1 class="text-4xl font-semibold tracking-tight text-zinc-900 dark:text-white">Pricing</h1>
        <p class="mx-auto mt-4 max-w-prose text-lg text-zinc-600 dark:text-zinc-300">
            One plan. Everything included. Free forever, because LibreNexus is
            open source software, not a subscription.
        </p>

        <div class="mt-12 rounded-xl border-2 border-brand-600 bg-white p-8 text-left shadow-sm dark:bg-zinc-900">
            <div class="flex items-baseline justify-between">
                <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">Free</h2>
                <p class="text-3xl font-semibold text-zinc-900 dark:text-white">
                    0 &euro;<span class="text-sm font-normal text-zinc-500 dark:text-zinc-400"> / forever</span>
                </p>
            </div>
            <ul class="mt-6 grid gap-3 text-sm text-zinc-700 sm:grid-cols-2 dark:text-zinc-300">
                @foreach ([
                    'Unlimited staff members',
                    'Unlimited services',
                    'Unlimited appointments',
                    'Public booking page',
                    'Email confirmations and reminders',
                    'Customer self-service cancellation',
                    'Multiple offices per account',
                    'Roles and permissions',
                ] as $feature)
                    <li class="flex items-start gap-2">
                        <flux:icon.check class="mt-0.5 size-4 shrink-0 text-brand-600 dark:text-brand-400" />
                        {{ $feature }}
                    </li>
                @endforeach
            </ul>
            <a href="{{ route('register') }}" class="mt-8 block rounded-md bg-brand-600 px-5 py-3 text-center text-sm font-medium text-white hover:bg-brand-700 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600">
                Create your free account
            </a>
        </div>
    </section>

    <section aria-labelledby="faq-heading" class="border-t border-zinc-200 dark:border-zinc-800">
        <div class="mx-auto w-full max-w-3xl px-4 py-16 sm:px-6">
            <h2 id="faq-heading" class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Frequently asked questions</h2>
            <dl class="mt-8 space-y-8">
                <div>
                    <dt class="font-medium text-zinc-900 dark:text-white">Is it really free?</dt>
                    <dd class="mt-2 max-w-prose text-sm text-zinc-600 dark:text-zinc-400">
                        Yes. LibreNexus is MIT-licensed open source software. There is no paid
                        tier, no usage limit, and no feature behind a paywall.
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-zinc-900 dark:text-white">Can I self-host it?</dt>
                    <dd class="mt-2 max-w-prose text-sm text-zinc-600 dark:text-zinc-400">
                        Yes. The source code and setup instructions are public. You need PHP,
                        PostgreSQL, and a mail provider; the
                        <a href="{{ route('docs') }}" class="font-medium text-brand-700 underline hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">documentation</a>
                        walks you through it.
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-zinc-900 dark:text-white">What is the catch?</dt>
                    <dd class="mt-2 max-w-prose text-sm text-zinc-600 dark:text-zinc-400">
                        There is none. The project exists to prove that a verifiably
                        high-quality product can be built and given away. See the
                        <a href="{{ route('open-source') }}" class="font-medium text-brand-700 underline hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">open-source page</a>
                        for the quality evidence.
                    </dd>
                </div>
            </dl>
        </div>
    </section>
</x-layouts.public>
