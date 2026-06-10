<x-layouts.public title="Privacy policy">
    <div class="mx-auto w-full max-w-3xl px-4 py-16 sm:px-6">
        <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">Privacy policy</h1>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Last updated: June 10, 2026</p>

        <div class="mt-8 max-w-prose space-y-8 text-sm text-zinc-700 dark:text-zinc-300">
            <section aria-labelledby="privacy-what-h">
                <h2 id="privacy-what-h" class="text-lg font-semibold text-zinc-900 dark:text-white">What we store</h2>
                <p class="mt-2">
                    For office accounts: your name, email address, and password (stored as a
                    secure hash). For appointments: the customer name, email address, optional
                    phone number, and notes entered while booking. We store nothing else about
                    customers and we never create customer accounts.
                </p>
            </section>

            <section aria-labelledby="privacy-why-h">
                <h2 id="privacy-why-h" class="text-lg font-semibold text-zinc-900 dark:text-white">Why we store it</h2>
                <p class="mt-2">
                    Appointment data exists solely so the office you booked with can run its
                    schedule and contact you about your visit: confirmations, reminders, and
                    cancellation notices. Data is never sold, shared across offices, or used
                    for advertising.
                </p>
            </section>

            <section aria-labelledby="privacy-isolation-h">
                <h2 id="privacy-isolation-h" class="text-lg font-semibold text-zinc-900 dark:text-white">Data isolation</h2>
                <p class="mt-2">
                    Every office (tenant) is strictly isolated. Members of one office can
                    never read another office's staff, services, customers, or appointments.
                    If you book with two offices, each holds its own separate record of you.
                </p>
            </section>

            <section aria-labelledby="privacy-control-h">
                <h2 id="privacy-control-h" class="text-lg font-semibold text-zinc-900 dark:text-white">Your control</h2>
                <p class="mt-2">
                    Customers can view and cancel appointments through the manage link in
                    their confirmation email. Account holders can update or delete their
                    account in settings; deleting an office removes its scheduling data,
                    including customer records. For anything else, contact the office you
                    booked with or the operator named in the
                    <a href="{{ route('imprint') }}" class="font-medium text-brand-700 underline hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">imprint</a>.
                </p>
            </section>

            <section aria-labelledby="privacy-cookies-h">
                <h2 id="privacy-cookies-h" class="text-lg font-semibold text-zinc-900 dark:text-white">Cookies</h2>
                <p class="mt-2">
                    We set only the cookies required to operate the application: a session
                    cookie and a security (CSRF) token. There are no analytics, tracking, or
                    third-party cookies.
                </p>
            </section>
        </div>
    </div>
</x-layouts.public>
