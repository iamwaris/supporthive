# SupportHive — Engineering Rules

Binding rules for every change in this repository, human or AI. If a rule blocks
something you need, change the rule in a PR — do not work around it silently.

## Stack

| Layer | Choice |
|---|---|
| Language | Core PHP 8.3 (no framework), `declare(strict_types=1)` in every file |
| Architecture | MVC-ish: `app/Core` (framework), `app/Controllers`, `app/Models`, `app/Views` |
| Database | MySQL 8 / MariaDB 10.6+, PDO only, `utf8mb4_unicode_ci` |
| Auth | PHP sessions, `password_hash()` |
| CSS | Tailwind CSS 4 (compiled, not CDN) |
| JS | Alpine.js for interactivity, ApexCharts for charts, Lucide for icons |
| Tables | Server-side pagination + Alpine enhancement (no jQuery/DataTables) |
| Tooling | Composer for dev tools only — the app runs with an empty `vendor/` |

Runtime dependencies are deliberately zero, and adding a Composer package the
app *requires at runtime* needs an explicit decision recorded in
`docs/PLAN.md`. The host does have SSH, so this is a choice rather than a
constraint: fewer moving parts, no `vendor/` to ship, nothing to rebuild on the
server, and a much smaller supply-chain surface.

## Non-negotiable security rules

1. **Every SQL query is a prepared statement with bound parameters.** No string
   concatenation or interpolation into SQL, ever — not even for "safe" integers.
   Table/column names cannot be bound, so they go through
   `Database::identifier()`, which allow-lists the characters.
2. **Every value printed in a view goes through `e()`.** `<?= e($x) ?>`, never
   `<?= $x ?>`. The only exception is already-rendered view output (`$content`),
   and it must carry a comment saying so.
3. **Every state-changing request is POST and CSRF-checked.** `Csrf::check()`
   runs in `Router::dispatch()` for all unsafe methods; forms include
   `csrf_field()`. GET must never change state.
4. **Every request value is validated before use** via `Validator`, and only
   `validated()` output reaches a model. Never pass `$_POST` to a write.
5. **Authorisation is checked server-side on every request** — `auth` / `role:`
   middleware or an explicit `Auth::requireRole()`. Hiding a link in a view is
   not access control. Also verify ownership: a valid session does not entitle
   a user to *this* record's id.
6. **Passwords use `password_hash()` / `password_verify()` only.** `md5`, `sha1`
   and hand-rolled salting are banned in `phpcs.xml`.
7. **Secrets live only in `.env`**, which is never committed and never inside
   the web root. Rotate anything that gets committed by accident — treat it as
   public from that moment.
8. **Session id is rotated on every privilege change** (`Session::regenerate()`
   via `Auth::login()` / `logout()`).
9. **Unauthenticated endpoints are rate-limited** with `RateLimiter::guard()`.
10. **Uploads go through `Upload::store()`.** MIME sniffed from content,
    generated filename, allow-listed extension, `public/uploads/.htaccess`
    blocks execution. Never trust `$_FILES['type']` or the client filename.
11. **Errors never leak internals to the browser.** `APP_DEBUG=false` in
    production; messages, traces and SQL go to `storage/logs` only.
12. **Never log secrets.** `Logger` redacts common key names; do not defeat it.
13. **Redirect targets are relative paths only** (`Http::redirect()` strips
    absolute URLs) to prevent open redirects.
14. **No `eval`, `exec`, `system`, `shell_exec`, `extract`, dynamic `include`
    from user input, or `unserialize()` of user data.** Use `json_decode()`.

## Code quality rules

- PSR-12, enforced by `composer cs`. 4-space indent, 120-column soft limit.
- Strict types everywhere; type every parameter, return and property. PHPStan
  level 5 must pass (`composer stan`).
- `final class` by default; `readonly` for value objects. Inheritance only where
  there is a deliberate extension point (`Controller`, `Model`).
- One class per file, PSR-4 under `App\`. Filename matches class name.
- Names say what a thing is: `TicketRepository`, `$unreadCount`,
  `markAsResolved()`. No `$data`, `$temp`, `$x`, `manager`, `helper`, `util`.
- Controllers stay thin: validate → delegate → respond. Business logic lives in
  models or dedicated service classes; SQL lives in models, never in views.
- Views contain presentation only — no queries, no writes, no authorisation
  decisions.
- Comments explain *why*, never *what*. Delete commented-out code.
- No dead code, no speculative abstraction. Build what the plan needs now.
- Every new table gets a migration file in `database/migrations`, named
  `YYYY_MM_DD_NNNNNN_description.sql`, written to be safely re-runnable.
- Tests: unit-test anything with branching logic (validation, permissions,
  pricing, state machines). A bug fix gets a regression test.

## Design & UX rules

- **Mobile-first.** Build the narrow layout, then add `sm:` / `md:` / `lg:`.
- **Tokens, not magic values.** Colours, radii and fonts come from the
  `@theme` block in `public/assets/css/app.src.css`.
- **Tailwind class names must appear literally in the source.** Never build a
  class by string interpolation — the compiler won't see it and the style
  silently vanishes in production.
- **Accessibility is a requirement, not a nice-to-have:** semantic elements,
  a `<label>` for every input, visible focus rings, 4.5:1 text contrast,
  keyboard operability, `aria-live` for async updates, alt text on meaningful
  images. Never signal state with colour alone.
- **Every form field shows its own error message** next to the field, with the
  submitted value preserved via `old()`.
- **Every list has an empty state, a loading state and an error state.**
- **Destructive actions confirm first** and are POST with CSRF.
- Consistency over novelty: reuse the `btn` / `card` / `input` primitives; promote
  a new primitive only after the third repetition.
- Pages must work without JavaScript for core flows. Alpine enhances; it is not
  load-bearing for validation or authorisation.

## Workflow

Decision 2026-09-24: commit directly to `main`, no feature branches or PR
review gate. The solo-owner/no-review reality made the PR step pure
overhead; the security and quality checklists it used to carry now live in
Definition of Done below and still apply to every change, just self-checked
before pushing rather than posted for review.

1. Update `docs/TRACKER.md` when a task starts and when it lands.
2. Before pushing: `composer check` and `npm run build`; walk the security
   and quality items in Definition of Done.
3. Push to `main`. CI runs automatically as a safety net (lint, static
   analysis, tests, secret scan) — it does not gate the deploy, so treat a
   red CI run on `main` as urgent, not informational.
4. `main` deploys to production automatically on every push.

## Definition of done

- [ ] Works on localhost against a fresh migration run
- [ ] `composer check` clean; `php scripts/preflight.php` has no new failures
- [ ] All SQL uses bound parameters — no interpolation anywhere
- [ ] All output escaped with `e()`; any exception is commented and justified
- [ ] State changes are POST and CSRF-protected
- [ ] Inputs validated via `Validator`; only `validated()` reaches persistence
- [ ] Authorisation checked server-side **and record ownership verified**
- [ ] New anonymous endpoints are rate-limited
- [ ] No secrets in code, comments, logs or fixtures
- [ ] Responsive at 375 px, 768 px, 1280 px; keyboard navigable
- [ ] Empty, loading and error states handled
- [ ] Migration added for any schema change, safely re-runnable
- [ ] Errors handled and logged; no debug output left behind
- [ ] Tests added or updated for anything with branching logic; a bug fix gets a regression test
- [ ] `docs/TRACKER.md` updated
