<x-layouts.public title="Open source">
    <div class="mx-auto w-full max-w-3xl px-4 py-16 sm:px-6">
        <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">Open source, verifiably built</h1>
        <p class="mt-4 max-w-prose text-zinc-600 dark:text-zinc-300">
            LibreNexus is MIT-licensed and was built against a predefined, public,
            reproducible quality benchmark. Every claim below links to evidence you
            can check yourself.
        </p>

        <section aria-labelledby="links-heading" class="mt-10">
            <h2 id="links-heading" class="text-xl font-semibold text-zinc-900 dark:text-white">Project links</h2>
            <ul class="mt-4 space-y-3 text-sm">
                <li class="flex items-start gap-2">
                    <flux:icon.code-bracket class="mt-0.5 size-4 shrink-0 text-brand-600 dark:text-brand-400" />
                    <span><a href="{{ config('app.repository_url') }}" rel="noopener" class="font-medium text-brand-700 underline hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">Source code on GitHub</a>, the complete application, specs, and quality pipeline.</span>
                </li>
                <li class="flex items-start gap-2">
                    <flux:icon.scale class="mt-0.5 size-4 shrink-0 text-brand-600 dark:text-brand-400" />
                    <span><a href="{{ config('app.repository_url') }}/blob/main/LICENSE" rel="noopener" class="font-medium text-brand-700 underline hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">MIT license</a>, use it, change it, sell services on it.</span>
                </li>
                <li class="flex items-start gap-2">
                    <flux:icon.check-badge class="mt-0.5 size-4 shrink-0 text-brand-600 dark:text-brand-400" />
                    <span><a href="{{ config('app.repository_url') }}/actions" rel="noopener" class="font-medium text-brand-700 underline hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">Continuous integration runs</a>, every push re-runs the full gate pipeline in public.</span>
                </li>
            </ul>
        </section>

        <section aria-labelledby="evidence-heading" class="mt-10">
            <h2 id="evidence-heading" class="text-xl font-semibold text-zinc-900 dark:text-white">Quality evidence</h2>
            <p class="mt-3 max-w-prose text-sm text-zinc-600 dark:text-zinc-400">
                The benchmark is defined in the repository under
                <code class="rounded bg-zinc-100 px-1 py-0.5 text-xs dark:bg-zinc-800">specs/</code>
                and measured by tools, not opinions:
            </p>
            <ul class="mt-4 grid gap-2 text-sm text-zinc-700 sm:grid-cols-2 dark:text-zinc-300">
                @foreach ([
                    'Tests with line coverage of 80% or more',
                    'Mutation score of 70% or more',
                    'Static analysis at PHPStan level 7, zero errors',
                    'Zero known dependency vulnerabilities',
                    'Secret scanning and SAST, zero findings',
                    'WCAG 2.1 AA accessibility on public pages',
                    'Lighthouse performance budget of 90 or more',
                    'A published software bill of materials (SBOM)',
                ] as $item)
                    <li class="flex items-start gap-2">
                        <flux:icon.check class="mt-0.5 size-4 shrink-0 text-brand-600 dark:text-brand-400" />
                        {{ $item }}
                    </li>
                @endforeach
            </ul>
            <p class="mt-4 max-w-prose text-sm text-zinc-600 dark:text-zinc-400">
                The full results, including the final quality report, coverage and mutation
                numbers, security scans, and the SBOM, are published in the repository under
                <a href="{{ config('app.repository_url') }}/tree/main/docs" rel="noopener" class="font-medium text-brand-700 underline hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">docs/</a>
                and
                <a href="{{ config('app.repository_url') }}/tree/main/reports" rel="noopener" class="font-medium text-brand-700 underline hover:text-brand-800 dark:text-brand-300 dark:hover:text-brand-200">reports/</a>.
            </p>
        </section>

        <section aria-labelledby="reproduce-heading" class="mt-10">
            <h2 id="reproduce-heading" class="text-xl font-semibold text-zinc-900 dark:text-white">Reproduce the benchmark</h2>
            <p class="mt-3 max-w-prose text-sm text-zinc-600 dark:text-zinc-400">
                With PHP 8.4, PostgreSQL, and Node installed, two commands rebuild the
                application and re-run every quality gate from a clean checkout:
            </p>
            <pre class="mt-4 overflow-x-auto rounded-lg bg-zinc-900 p-4 text-sm text-zinc-100 dark:bg-zinc-800"><code>make setup
make verify</code></pre>
        </section>
    </div>
</x-layouts.public>
