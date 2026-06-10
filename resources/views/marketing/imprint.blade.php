<x-layouts.public title="Imprint">
    <div class="mx-auto w-full max-w-3xl px-4 py-16 sm:px-6">
        <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">Imprint</h1>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Legal notice for this installation</p>

        <div class="mt-8 max-w-prose space-y-8 text-sm text-zinc-700 dark:text-zinc-300">
            <section aria-labelledby="imprint-operator-h">
                <h2 id="imprint-operator-h" class="text-lg font-semibold text-zinc-900 dark:text-white">Operator</h2>
                <p class="mt-2">
                    This is a self-hosted installation of LibreNexus, free open source
                    software. The operator of this specific installation is responsible for
                    its content and data processing:
                </p>
                <p class="mt-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    LibreNexus Demo Installation<br>
                    Operated for demonstration purposes<br>
                    Contact: {{ config('mail.from.address') }}
                </p>
            </section>

            <section aria-labelledby="imprint-software-h">
                <h2 id="imprint-software-h" class="text-lg font-semibold text-zinc-900 dark:text-white">About the software</h2>
                <p class="mt-2">
                    LibreNexus is published under the MIT license. The source code,
                    documentation, and quality evidence are available in the
                    <a href="{{ config('app.repository_url') }}" rel="noopener" class="font-medium text-brand-700 underline hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">public repository</a>.
                    The software is provided "as is", without warranty of any kind, as stated
                    in the license.
                </p>
            </section>

            <section aria-labelledby="imprint-liability-h">
                <h2 id="imprint-liability-h" class="text-lg font-semibold text-zinc-900 dark:text-white">Liability for content</h2>
                <p class="mt-2">
                    Offices (tenants) using this installation are responsible for the
                    services, prices, and descriptions they publish on their booking pages.
                    If you believe content on a booking page is unlawful, contact the
                    operator above.
                </p>
            </section>
        </div>
    </div>
</x-layouts.public>
