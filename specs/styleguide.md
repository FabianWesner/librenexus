# LibreNexus — Style Guide

**Direction: clean, professional SaaS.** Calm, trustworthy, and uncluttered — in
the spirit of Linear/Stripe: a neutral base, generous whitespace, one confident
accent, and restrained typography. The product should look like something a
small office would trust with their calendar.

Built on the existing stack: **Flux UI + Tailwind 4**, with the theme tokens in
`resources/css/app.css`. Reuse Flux components and existing Blade components
before writing new ones (project guardrail).

## Brand & tone

- **Name:** LibreNexus. **Tone:** competent, friendly, plain-spoken. No hype.
- **Voice in UI copy:** short, action-oriented ("Add a service", "Share your
  booking link"). Avoid jargon. Never use the em-dash in product copy; use a
  comma or rephrase.

## Color

- **Neutral base:** the `zinc` scale already defined in `app.css` for
  backgrounds, text, and borders. Light mode default; dark mode supported via the
  existing `.dark` variant (FR-SETTINGS-2).
- **One accent:** a single confident brand color used for primary actions, links,
  active nav, and focus rings. Define it as `--color-brand-*` tokens in `app.css`
  (a calm indigo/blue is recommended). Keep the existing `--color-accent`
  mechanism working for Flux.
- **Semantic colors:** success (green), warning (amber), danger (red), info
  (blue) — used sparingly and always paired with text/icon, never color alone
  (NFR-A11Y-3).
- **Contrast:** all text/UI must meet WCAG 2.1 AA (QG-A11Y). Verify accent-on-
  white and white-on-accent combinations specifically.
- **Staff/service colors:** the small palette used for calendar chips must each
  pass contrast against their backgrounds.

## Typography

- **Font:** Instrument Sans (already configured) with system fallbacks.
- **Scale:** a clear hierarchy — page title (2xl/3xl, semibold), section heading
  (lg/xl, medium), body (base), meta/caption (sm, muted). Don't invent more than
  ~5 sizes.
- **Line length:** marketing prose capped (~`max-w-prose`); never full-bleed
  paragraphs.

## Layout & spacing

- **Grid:** consistent Tailwind spacing scale; prefer multiples of 4. Generous
  padding inside cards and around sections.
- **App shell:** the existing sidebar/header layout (`layouts/app`) for
  authenticated screens; a lighter public layout with the global footer for
  marketing/legal pages.
- **Containers:** centered, max-width content; comfortable gutters on mobile.
- **Density:** lists and tables are scannable, not cramped; calendar/day views
  prioritize legibility over packing more in.

## Components (reuse first)

- Use Flux for buttons, inputs, selects, modals, tables, badges, tooltips,
  date/time pickers, navlists, dropdowns.
- **Buttons:** one primary action per view (accent, solid); secondary actions are
  subtle/ghost; destructive actions are danger and confirmed via modal.
- **Forms:** labels always visible; inline validation messages; disabled submit
  while invalid; clear required markers. Errors announced for screen readers.
- **Empty states:** every list/dashboard has a helpful empty state with a single
  clear next action (supports onboarding, FR-DASH-2).
- **Feedback:** use toasts/inline confirmations for success; never silent.
- **Status:** appointment statuses shown as labeled badges (text + color +
  optional icon).

## Responsiveness

- Mobile-first. Breakpoints: base (mobile), `md` (tablet), `lg`+ (desktop).
- The public booking flow (FR-BOOK-6) and marketing pages must be fully usable
  on a phone. Sidebar collapses to a mobile nav (existing pattern).

## Accessibility (non-negotiable, QG-A11Y)

- Semantic HTML and landmarks (`header`, `nav`, `main`, `footer`).
- All interactive elements keyboard-reachable with a visible focus ring (the
  existing accent focus-ring pattern).
- Form fields have associated labels; errors linked via `aria-describedby`.
- Color contrast AA; color never the sole signal.
- Images have alt text; icons used as buttons have accessible names.

## Motion

- Subtle and fast (≤ 200ms) transitions for hovers, modals, and disclosure.
- Respect `prefers-reduced-motion`.

## Don'ts

- No generic "AI gradient" hero clutter, no random emoji in product chrome, no
  more than one accent color, no dense walls of text.
- No inline styles that bypass the theme; no unbundled `<script>` that would
  violate CSP (SEC-HEADERS).
- Don't hand-format around Pint or invent component variants when Flux has one.

## Definition of "looks done"

A screen looks done when: it uses the shell + theme tokens, has a single clear
primary action, handles empty/loading/error states, is keyboard-navigable, meets
AA contrast, and is legible on a phone.
