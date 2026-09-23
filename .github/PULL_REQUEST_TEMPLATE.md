## What changed

<!-- One or two sentences. Link the tracker ID, e.g. M1-2. -->

Tracker: `M?-?`

## Why

<!-- The problem this solves. -->

## How to test

1.
2.

## Security checklist (required)

- [ ] All SQL uses bound parameters — no interpolation anywhere
- [ ] All output escaped with `e()`; any exception is commented and justified
- [ ] State changes are POST and CSRF-protected
- [ ] Inputs validated via `Validator`; only `validated()` reaches persistence
- [ ] Authorisation checked server-side **and record ownership verified**
- [ ] New anonymous endpoints are rate-limited
- [ ] No secrets in code, comments, logs or fixtures
- [ ] Error paths leak nothing to the browser
- [ ] Uploads (if any) go through `Upload::store()`

## Quality checklist

- [ ] `composer check` passes
- [ ] `npm run build` passes; no interpolated Tailwind class names
- [ ] Responsive at 375 / 768 / 1280 px; keyboard navigable; labels present
- [ ] Empty, loading and error states handled
- [ ] Migration added for any schema change, safely re-runnable
- [ ] Tests added or updated
- [ ] `docs/TRACKER.md` updated
