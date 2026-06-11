<x-layouts.public>
    {{-- Hero --}}
    <section class="relative overflow-hidden">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(theme(colors.zinc.200)_1px,transparent_1px)] [background-size:24px_24px] opacity-60 dark:bg-[radial-gradient(theme(colors.zinc.800)_1px,transparent_1px)]" aria-hidden="true"></div>

        <div class="relative mx-auto grid w-full max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24">
            <div class="max-w-xl">
                <p class="mb-4 inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-medium text-brand-800 dark:border-brand-800 dark:bg-brand-950 dark:text-brand-200">
                    Free and open source, MIT licensed
                </p>
                <h1 class="text-4xl font-semibold tracking-tight text-zinc-900 sm:text-5xl dark:text-white">
                    Appointment scheduling your office actually controls
                </h1>
                <p class="mt-5 max-w-prose text-lg text-zinc-600 dark:text-zinc-300">
                    LibreNexus gives clinics, salons, studios, and advisors a public booking
                    page, staff availability, and automatic confirmations. No fees, no lock-in.
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-4">
                    <a href="{{ route('register') }}" class="rounded-md bg-brand-600 px-5 py-3 text-sm font-medium text-white hover:bg-brand-700 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600">
                        Get started free
                    </a>
                    <a href="{{ url('/demo-clinic') }}" class="rounded-md px-2 py-3 text-sm font-medium text-brand-700 hover:text-brand-800 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-brand-300 dark:hover:text-brand-200">
                        See a demo booking page
                    </a>
                </div>
            </div>

            {{-- Booking calendar mock, pure CSS --}}
            <div class="relative" aria-hidden="true">
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-lg shadow-zinc-200/60 dark:border-zinc-800 dark:bg-zinc-900 dark:shadow-none">
                    <div class="flex items-center justify-between border-b border-zinc-100 pb-4 dark:border-zinc-800">
                        <div>
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Northside Physio</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Initial consultation, 45 min</p>
                        </div>
                        <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300">Step 3 of 5</span>
                    </div>
                    <div class="grid grid-cols-7 gap-1.5 py-4 text-center text-xs text-zinc-500 dark:text-zinc-400">
                        <span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span>
                        @foreach ([3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16] as $day)
                            <span @class([
                                'rounded-md py-1.5',
                                'bg-brand-600 font-semibold text-white' => $day === 11,
                                'text-zinc-700 dark:text-zinc-300' => in_array($day, [4, 5, 6, 12, 13]),
                                'text-zinc-500 line-through dark:text-zinc-500' => ! in_array($day, [4, 5, 6, 11, 12, 13]),
                            ])>{{ $day }}</span>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-3 gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                        @foreach (['09:00', '09:45', '10:30', '11:15', '14:00', '14:45'] as $slot)
                            <span @class([
                                'rounded-md border px-2 py-1.5 text-center text-xs font-medium',
                                'border-brand-600 bg-brand-600 text-white' => $slot === '10:30',
                                'border-zinc-200 text-zinc-700 dark:border-zinc-700 dark:text-zinc-300' => $slot !== '10:30',
                            ])>{{ $slot }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Features --}}
    <section aria-labelledby="features-heading" class="border-t border-zinc-200 dark:border-zinc-800">
        <div class="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6">
            <h2 id="features-heading" class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                Everything a small office needs to take bookings
            </h2>
            <div class="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-2">
                    <flux:icon.calendar-days class="size-6 text-brand-600 dark:text-brand-400" />
                    <h3 class="font-medium text-zinc-900 dark:text-white">Public booking page</h3>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">A shareable link where customers pick a service, a person, and a time. No customer accounts needed.</p>
                </div>
                <div class="space-y-2">
                    <flux:icon.clock class="size-6 text-brand-600 dark:text-brand-400" />
                    <h3 class="font-medium text-zinc-900 dark:text-white">Real availability</h3>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">Weekly working hours, time off, buffers, and lead times. Double bookings are impossible by design.</p>
                </div>
                <div class="space-y-2">
                    <flux:icon.envelope class="size-6 text-brand-600 dark:text-brand-400" />
                    <h3 class="font-medium text-zinc-900 dark:text-white">Automatic emails</h3>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">Confirmations, reminders, and cancellations, with a self-service link so customers manage their own visits.</p>
                </div>
                <div class="space-y-2">
                    <flux:icon.users class="size-6 text-brand-600 dark:text-brand-400" />
                    <h3 class="font-medium text-zinc-900 dark:text-white">Teams and roles</h3>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">Owners, admins, and staff with the right permissions. Run several offices from one account.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Open source strip --}}
    <section aria-labelledby="open-heading" class="border-t border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mx-auto flex w-full max-w-6xl flex-col items-start justify-between gap-6 px-4 py-12 sm:px-6 lg:flex-row lg:items-center">
            <div class="max-w-prose">
                <h2 id="open-heading" class="text-xl font-semibold text-zinc-900 dark:text-white">Why is it free?</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    LibreNexus is open source under the MIT license. You can read every line,
                    self-host it, and verify the quality benchmark it was built against.
                </p>
            </div>
            <a href="{{ route('open-source') }}" class="rounded-md border border-zinc-300 bg-white px-4 py-2.5 text-sm font-medium text-zinc-800 hover:bg-zinc-100 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700">
                Read about the project
            </a>
        </div>
    </section>
</x-layouts.public>
