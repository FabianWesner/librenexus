<x-layouts.public title="Documentation">
    <div class="mx-auto grid w-full max-w-6xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-[14rem_1fr]">
        <nav aria-label="Documentation sections" class="lg:sticky lg:top-8 lg:self-start">
            <p class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">On this page</p>
            <ul class="space-y-2 border-l border-zinc-200 text-sm dark:border-zinc-800">
                @foreach ([
                    'getting-started' => 'Getting started',
                    'add-staff' => 'Add staff',
                    'add-services' => 'Add services',
                    'set-availability' => 'Set availability',
                    'booking' => 'Share your booking link',
                    'manage-appointments' => 'Manage appointments',
                    'self-hosting' => 'Self-hosting',
                ] as $anchor => $label)
                    <li>
                        <a href="#{{ $anchor }}" class="-ml-px block border-l-2 border-transparent py-0.5 pl-4 text-zinc-600 hover:border-brand-600 hover:text-zinc-900 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-zinc-400 dark:hover:text-white">
                            {{ $label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <article class="max-w-prose space-y-12">
            <header>
                <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">User manual</h1>
                <p class="mt-3 text-zinc-600 dark:text-zinc-300">
                    Everything you need to set up your office and start taking bookings.
                    Setup takes about ten minutes.
                </p>
            </header>

            <section id="getting-started" aria-labelledby="getting-started-h">
                <h2 id="getting-started-h" class="text-xl font-semibold text-zinc-900 dark:text-white">Getting started</h2>
                <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-zinc-700 dark:text-zinc-300">
                    <li>Create an account with your name, email, and a password.</li>
                    <li>Verify your email address using the link we send you.</li>
                    <li>Your personal workspace is created automatically. Rename it, or create a
                        separate workspace per office, in workspace settings.</li>
                    <li>Set your timezone and contact email in workspace settings. The timezone
                        controls how all appointment times are shown and calculated.</li>
                </ol>
            </section>

            <section id="add-staff" aria-labelledby="add-staff-h">
                <h2 id="add-staff-h" class="text-xl font-semibold text-zinc-900 dark:text-white">Add staff</h2>
                <p class="mt-3 text-sm text-zinc-700 dark:text-zinc-300">
                    Staff members are the bookable people in your office. Go to
                    <strong>Staff</strong>, choose <strong>Add staff</strong>, and give each
                    person a name, an email, and a calendar color. A staff member does not need
                    their own login; you can optionally link one to a workspace member so they
                    manage their own schedule.
                </p>
            </section>

            <section id="add-services" aria-labelledby="add-services-h">
                <h2 id="add-services-h" class="text-xl font-semibold text-zinc-900 dark:text-white">Add services</h2>
                <p class="mt-3 text-sm text-zinc-700 dark:text-zinc-300">
                    Services are what customers book: a consultation, a haircut, a session.
                    Each service has a duration (5 minutes to 8 hours), optional preparation
                    and wrap-up buffers, and an optional price. Assign each service to the
                    staff members who can perform it.
                </p>
            </section>

            <section id="set-availability" aria-labelledby="set-availability-h">
                <h2 id="set-availability-h" class="text-xl font-semibold text-zinc-900 dark:text-white">Set availability</h2>
                <p class="mt-3 text-sm text-zinc-700 dark:text-zinc-300">
                    Each staff member has weekly working hours, for example Monday to Friday,
                    09:00 to 17:00. Add time off for holidays or appointments outside
                    LibreNexus. Bookable times are calculated from working hours minus
                    existing appointments, time off, and your booking policy (minimum notice
                    and how far ahead customers may book).
                </p>
            </section>

            <section id="booking" aria-labelledby="booking-h">
                <h2 id="booking-h" class="text-xl font-semibold text-zinc-900 dark:text-white">Share your booking link</h2>
                <p class="mt-3 text-sm text-zinc-700 dark:text-zinc-300">
                    Your workspace has a public booking page at a stable address you can put
                    on your website, social profiles, or email signature. Customers pick a
                    service, a staff member (or "any available"), and a time, then enter their
                    name and email. They get a confirmation email with a link to view, cancel,
                    or reschedule their appointment, no account required.
                </p>
            </section>

            <section id="manage-appointments" aria-labelledby="manage-appointments-h">
                <h2 id="manage-appointments-h" class="text-xl font-semibold text-zinc-900 dark:text-white">Manage appointments</h2>
                <p class="mt-3 text-sm text-zinc-700 dark:text-zinc-300">
                    The appointments list and calendar show everything booked in your
                    workspace. Filter by staff, service, or date. You can book for a customer
                    manually, reschedule, mark visits as completed or no-show, and cancel with
                    an automatic notification email. Staff members see their own schedule;
                    admins see everything.
                </p>
            </section>

            <section id="self-hosting" aria-labelledby="self-hosting-h">
                <h2 id="self-hosting-h" class="text-xl font-semibold text-zinc-900 dark:text-white">Self-hosting</h2>
                <p class="mt-3 text-sm text-zinc-700 dark:text-zinc-300">
                    LibreNexus is a Laravel application backed by PostgreSQL. Clone the
                    <a href="{{ config('app.repository_url') }}" rel="noopener" class="font-medium text-brand-700 underline hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">repository</a>,
                    run <code class="rounded bg-zinc-100 px-1 py-0.5 text-xs dark:bg-zinc-800">make setup</code>,
                    configure your mail provider in <code class="rounded bg-zinc-100 px-1 py-0.5 text-xs dark:bg-zinc-800">.env</code>,
                    and serve it with any PHP host. The same command CI uses,
                    <code class="rounded bg-zinc-100 px-1 py-0.5 text-xs dark:bg-zinc-800">make verify</code>,
                    lets you re-run the full quality benchmark yourself.
                </p>
            </section>
        </article>
    </div>
</x-layouts.public>
