# Design System

Tailwind CSS 4 + Alpine.js + Lucide + ApexCharts, all vendored and served
same-origin. The goal is a product that looks deliberate and consistent, built
from a small set of primitives rather than ad-hoc styling per page.

---

## 1. Why this stack

| Choice | Reason |
|---|---|
| **Tailwind, compiled** | Styling lives next to the markup it affects, so nothing is afraid to change. Compiled (not the CDN script) because the CDN build is slow, unversioned, and forces the CSP open. |
| **Alpine.js** | Sprinkle-sized interactivity — dropdowns, modals, tabs — with the server still rendering HTML. No build step, no virtual DOM, no client-side router to keep in sync with PHP. |
| **Lucide** | Consistent, MIT-licensed icon set. Tree-shakeable; the UMD build is ~30 KB. |
| **ApexCharts** | Good-looking charts with sane defaults and accessible tooltips. |
| **No DataTables / jQuery** | DataTables needs jQuery, ships its own conflicting styles, and requires loading the whole table into the browser. Server-side pagination is faster, scales, and keeps authorisation server-side. |

### Worth considering later

- **htmx** alongside Alpine: it pairs unusually well with server-rendered PHP —
  a partial view returned from a controller swaps into the page, so filtering
  and pagination need no client-side state at all. Add it only when the first
  feature genuinely needs it.
- **Inter** (self-hosted via `.woff2`) instead of a system font stack, if you
  want the UI to feel more designed. Self-host — Google Fonts links would
  widen the CSP and add a third party.
- **Grid.js** if you later want sorting/filtering widgets without jQuery.

## 2. Tokens

Defined once in the `@theme` block of `public/assets/css/app.src.css`. Change
design decisions there, never in individual components.

| Token | Use |
|---|---|
| `--color-brand-500/600/700` | Primary actions, links, focus rings |
| `slate-50 / 200 / 500 / 900` | Surfaces, borders, muted text, body text |
| `emerald` / `amber` / `rose` | Success / warning / danger — **always with an icon or text label as well** |
| `--radius-card` | Card and modal corners |
| `--font-sans` | All text |

Spacing uses Tailwind's 4 px scale. Stick to `2 / 3 / 4 / 6 / 8 / 12` — a
consistent rhythm reads as designed; arbitrary values read as accidental.

## 3. Primitives

Base primitives (`btn`, `card`, `input`) are declared with `@utility` so the
variants below can `@apply` them - Tailwind v4 rejects `@apply` of a plain
`@layer components` class. Use these; extend them rarely.

| Class | Use |
|---|---|
| `.btn-primary` | The one main action per view |
| `.btn-ghost` | Secondary and cancel actions |
| `.card` | Standard content container |
| `.input-base` / `.input-error` | Form controls |
| `.label` / `.help` / `.error` | Field label, hint, and validation message |

A pattern earns a class after its **third** repetition. Before that, utilities
in the markup are clearer than a premature abstraction.

## 4. Layout

- Page shell: `mx-auto w-full max-w-7xl px-4 sm:px-6`.
- Mobile-first: write the narrow layout, then add `sm:` / `md:` / `lg:`.
- Test at **375 px**, **768 px** and **1280 px** before opening a PR.
- Content column for long text: `max-w-prose`.
- Vertical rhythm between sections: `space-y-6` (or `space-y-8` on desktop).

## 5. Component conventions

**Forms**

- Every input has a real `<label for>`. Placeholders are not labels.
- Errors appear beneath their own field in `.error`, and the field gets
  `.input-error`. A summary at the top alone is not enough.
- Preserve submitted values with `old('field')`.
- Disable the submit button during submission (Alpine), but never rely on that
  for correctness — the server re-validates regardless.
- Mark required fields visibly *and* with the `required` attribute.

**Tables**

- Server-paginated, 20–50 rows per page.
- Right-align and `tabular-nums` all numeric columns.
- Sticky header on long tables; horizontal scroll container on narrow screens.
- Row actions in the last column; destructive actions confirm first.
- Always provide an empty state with a next step, not just "No results".

**Charts (ApexCharts)**

- Data arrives as JSON from a dedicated endpoint — never echoed into an inline
  `<script>` (the CSP forbids it, and it is an XSS vector).
- Every chart needs a title, axis labels and units.
- Provide the same data as an accessible table or `aria-label` summary.
- Maximum ~6 series; beyond that, split the chart.

**Modals / dropdowns (Alpine)**

- Close on `Escape` and on outside click.
- Trap focus while open; return focus to the trigger on close.
- Modals need `role="dialog"` and `aria-modal="true"`.

**Feedback**

- Flash messages for outcomes of a redirect; inline errors for field problems.
- Async regions get `aria-live="polite"`.
- Any action over ~300 ms shows a loading state; use skeletons over spinners
  for content areas.

## 6. Accessibility floor

Not optional, checked in review:

- Semantic elements (`<button>` for actions, `<a>` for navigation — never a
  clickable `<div>`).
- Visible focus rings; never `outline: none` without a replacement.
- Text contrast ≥ 4.5:1 (≥ 3:1 for large text and UI borders).
- Full keyboard operability, in a logical tab order.
- `alt` text on meaningful images; `alt=""` on decorative ones.
- Icon-only buttons carry an `aria-label`.
- State is never conveyed by colour alone.
- A visible "skip to content" link (already in the layout).

## 7. Performance budget

- CSS ≤ 50 KB gzipped (Tailwind purges to well under this).
- JS ≤ 150 KB gzipped total; load ApexCharts **only** on pages with charts.
- Images: WebP, explicit `width`/`height` to prevent layout shift,
  `loading="lazy"` below the fold.
- Long-cache fingerprinted assets (already set in `public/.htaccess`); never
  cache HTML.

## 8. The one hard Tailwind rule

Tailwind only generates classes it can see **literally** in the source:

```php
<!-- BROKEN: the compiler never sees "bg-rose-50", so it is not generated -->
<div class="bg-<?= $tone ?>-50">

<!-- CORRECT: full class strings in a map -->
<?php $tones = ['error' => 'bg-rose-50 text-rose-800']; ?>
<div class="<?= $tones[$key] ?>">
```

This fails silently — it works locally with `npm run dev` watching, then
disappears in the production build. Check every dynamic class.
