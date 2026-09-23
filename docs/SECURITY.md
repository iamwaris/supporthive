# SupportHive — Security Practices

How security is implemented here, and what to check before every release.
The binding rules are in [`CLAUDE.md`](../CLAUDE.md); this document explains the
*mechanisms* and gives the review checklists.

---

## 1. Defence layers

| Layer | Mechanism | File |
|---|---|---|
| Transport | HTTPS redirect, HSTS (production only) | `public/.htaccess`, `app/Core/Http.php` |
| Web server | No directory listing, dotfile/backup denial, deny-all outside the web root | `public/.htaccess`, `.htaccess` |
| Headers | CSP, `nosniff`, `X-Frame-Options: DENY`, Referrer-Policy, Permissions-Policy, COOP/CORP | `app/Core/Http.php` |
| Session | Strict mode, HttpOnly + SameSite, 15-min id rotation, idle timeout, UA binding | `app/Core/Session.php` |
| Request | CSRF on every unsafe method, allow-list validation | `app/Core/Csrf.php`, `Validator.php` |
| Data | PDO prepared statements, emulation off, identifier allow-list | `app/Core/Database.php` |
| Identity | `password_hash()` + transparent rehash, timing-equalised failures | `app/Core/Auth.php` |
| Abuse | Fixed-window DB rate limiter | `app/Core/RateLimiter.php` |
| Files | Content-sniffed MIME, generated names, execution blocked in uploads | `app/Core/Upload.php`, `public/uploads/.htaccess` |
| Observability | Redacting logger, dedicated `security-*.log`, `audit_log` table | `app/Core/Logger.php` |
| Failure | Generic error pages; details to log only | `app/Core/ErrorHandler.php` |

## 2. The OWASP Top 10, mapped

| Risk | How it is handled here |
|---|---|
| A01 Broken access control | Explicit route table; `auth` / `role:` middleware; **ownership must be re-checked in the handler** — a session is not an entitlement to a specific record id |
| A02 Cryptographic failures | `password_hash()` only; tokens are 32 random bytes stored as SHA-256; HTTPS + HSTS; `APP_KEY` from `random_bytes` |
| A03 Injection | Bound parameters everywhere; `Database::identifier()` for names; `e()` on all output; no `eval`/`exec` (banned in PHPCS) |
| A04 Insecure design | Deny-by-default routing; rate limits on every anonymous endpoint; least-privilege DB user where the host allows it (§5) |
| A05 Misconfiguration | `APP_DEBUG=false` in production; `scripts/preflight.php` asserts it; app code outside the web root; deny-all `.htaccess` fallback |
| A06 Vulnerable components | Zero runtime dependencies; dev tools pinned; `npm audit` in CI |
| A07 Auth failures | Throttled logins, session rotation on privilege change, single-use expiring reset tokens, identical messaging for unknown-user vs bad-password |
| A08 Integrity failures | No `unserialize()` of user data; no CDN for app assets; deploys only from `main` via CI, over SSH with a pinned host key |
| A09 Logging failures | Security events logged with IP and user id; secrets redacted; `audit_log` is append-only |
| A10 SSRF | No user-controlled outbound requests. If one is ever added: allow-list the host, forbid redirects, block private IP ranges |

## 3. Known, accepted trade-offs

Both are in the CSP and are deliberate:

1. `script-src 'unsafe-eval'` — Alpine.js compiles `x-*` expressions with
   `new Function()`. **Removal path:** switch to `@alpinejs/csp` and move all
   logic into `x-data` component methods.
2. `style-src 'unsafe-inline'` — ApexCharts injects `<style>` at runtime. Lower
   severity than the script case, but revisit if we drop ApexCharts.

`script-src 'unsafe-inline'` is never acceptable — it converts any stored-XSS
bug into full account takeover.

## 4. Secrets

- Live only in `.env` (git-ignored) and GitHub Actions secrets.
- `.env` sits **outside** the web root on the host and is `chmod 600`.
- Generate `APP_KEY` with `php scripts/genkey.php`.
- If a secret is ever committed, it is public: rotate it, then clean history.
- Anything that reaches a log, screenshot or ticket is compromised — rotate.

## 5. Database privileges

**Locally**, the application's MySQL user is created by
`database/setup-local.sql` with only the rights it needs on its own schema.

**On Hostinger this cannot be enforced.** hPanel grants each database user full
privileges on its own database, with no UI to narrow them, so the production
user holds `DROP` and `ALTER` whether we want it to or not. That is a real
limitation of the platform, not something the application can fix, and the
mitigation is the layer above it: every query is a bound prepared statement and
no identifier is ever interpolated, so injected SQL has no route to DDL in the
first place. Revisit if the project moves to a VPS.

## 6. Code review checklist

Every PR that touches request handling:

- [ ] All SQL uses bound parameters; no interpolation
- [ ] All output escaped with `e()`; exceptions commented and justified
- [ ] Writes are POST and CSRF-checked
- [ ] Inputs validated; only `validated()` reaches persistence
- [ ] Authorisation checked **and ownership of the specific record verified**
- [ ] Anonymous endpoints rate-limited
- [ ] No secrets in code, logs or comments
- [ ] Error paths do not leak internals
- [ ] New tables have migrations and appropriate indexes
- [ ] `composer check` passes

## 7. Pre-launch checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE=true`
- [ ] `APP_KEY` set to a freshly generated value (not the local one)
- [ ] HTTPS enforced; certificate valid; HSTS confirmed in response headers
- [ ] `php scripts/preflight.php` fully green on the host
- [ ] Application directory not reachable over HTTP — verify by requesting
      `/../app/bootstrap.php`, `/.env`, `/composer.json`, `/.git/config`
- [ ] Upload execution blocked — upload a harmless `.txt`, then confirm a
      renamed `.php` cannot execute
- [ ] Default/seed accounts removed or given real passwords
- [ ] DB user privileges reviewed (see §5 — not narrowable on Hostinger)
- [ ] Automated backups running **and a restore has been tested**
- [ ] `storage/logs` writable, not web-readable, and rotating
- [ ] Error alerting in place
- [ ] Rate limits verified by actually exceeding them

## 8. Incident response

1. Rotate every credential: DB, SSH deploy key, SMTP, `APP_KEY`.
2. Preserve `storage/logs` and the host's access logs before changing anything.
3. Put the site in maintenance mode.
4. Establish scope from `audit_log` + `security-*.log`.
5. Patch, deploy from a clean `main`, invalidate all sessions (rotate `APP_KEY`).
6. Notify affected users if personal data was exposed.
7. Write the post-mortem into `docs/PLAN.md` §5 and add a regression test.

## 9. Reporting a vulnerability

See [`../SECURITY.md`](../SECURITY.md).
