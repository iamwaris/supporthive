# Deployment

Local (XAMPP) → GitHub → cPanel shared hosting over FTPS.

```
  localhost/XAMPP          GitHub                      cPanel host
  ───────────────          ──────                      ───────────
  feat/ branch    push ->  CI (lint, PHPStan,
                           tests, build, secrets)
       │                          │
       └── PR ──> main ──> Deploy workflow ──FTPS──>  /supporthive_app/  (code, .env)
                                              └────>  /public_html/      (public/)
                                   │
                                   └── smoke test: 200 OK + nothing sensitive readable
```

Nothing is ever uploaded by hand. Production is always exactly what is on `main`.

---

## 1. Why the two-directory layout

cPanel serves `public_html`. If the whole project lived there, then a single
PHP misconfiguration (a bad upgrade, `.htaccess` ignored, PHP handler disabled)
would expose `.env`, your source, and your logs as plain text.

So:

| Uploaded to | Contents | Web-reachable |
|---|---|---|
| `/supporthive_app/` | `app/`, `config/`, `database/`, `scripts/`, `storage/`, `.env` | no |
| `/public_html/` | `index.php`, `assets/`, `uploads/`, `.htaccess`, `robots.txt` | yes |

`public/index.php` looks for `../app/bootstrap.php` first (the local XAMPP
layout) and falls back to `../supporthive_app/app/bootstrap.php` (the host
layout), so the identical file works in both places.

> If your host puts `public_html` somewhere that has no writable sibling
> directory, set `APP_DIR` to any directory above the web root that you *can*
> write to, and adjust the fallback path in `public/index.php` to match.

## 2. Local setup (XAMPP)

```bash
# 1. Clone into the XAMPP web root
cd C:/xampp/htdocs
git clone https://github.com/<you>/SupportHive.git
cd SupportHive

# 2. Environment
cp .env.example .env
C:/xampp/php/php.exe scripts/genkey.php     # paste the output into APP_KEY

# 3. Database
C:/xampp/mysql/bin/mysql.exe -u root -e "CREATE DATABASE supporthive CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
C:/xampp/php/php.exe database/migrate.php

# 4. Front end
npm install
npm run vendor      # copies Alpine / Lucide / ApexCharts into public/assets/vendor
npm run build       # compiles Tailwind -> public/assets/css/app.css
#   (use `npm run dev` while working, it watches)

# 5. Dev tooling
composer install

# 6. Check
C:/xampp/php/php.exe scripts/preflight.php
```

Open **http://localhost/SupportHive/public/**.

Set `APP_URL=http://localhost/SupportHive/public` in `.env` to match.

Cleaner alternative — point an Apache virtual host at the `public` directory so
the URL has no `/public` in it. Add to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName supporthive.test
    DocumentRoot "C:/xampp/htdocs/SupportHive/public"
    <Directory "C:/xampp/htdocs/SupportHive/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Then add `127.0.0.1 supporthive.test` to `C:\Windows\System32\drivers\etc\hosts`,
restart Apache, and set `APP_URL=http://supporthive.test`.

## 3. One-time host setup

1. **Check the PHP version.** cPanel → *MultiPHP Manager* → set the domain to
   PHP 8.3 (8.2 minimum). Enable `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`.
2. **Create the database and user.** cPanel → *MySQL Databases*. Grant the user
   only `SELECT, INSERT, UPDATE, DELETE` on that database — not `ALL PRIVILEGES`.
3. **Create the application directory** at the account root (the level that
   *contains* `public_html`), e.g. `supporthive_app`. Leave it empty; the
   pipeline fills it.
4. **Create an FTP account** scoped as tightly as your host allows, and confirm
   FTPS (explicit TLS on port 21) works. Plain FTP sends the password in clear
   text — do not use it.
5. **Install the SSL certificate** (AutoSSL / Let's Encrypt) before the first
   deploy, since the app forces HTTPS.

## 4. GitHub configuration

> Step-by-step version with the exact UI paths: [GITHUB-SETUP.md](GITHUB-SETUP.md).

**Settings → Secrets and variables → Actions → Secrets:**

| Secret | Example | Notes |
|---|---|---|
| `FTP_SERVER` | `ftp.yourdomain.com` | hostname only |
| `FTP_USERNAME` | `deploy@yourdomain.com` | |
| `FTP_PASSWORD` | | |
| `APP_URL` | `https://yourdomain.com` | no trailing slash |
| `APP_KEY` | `base64:…` | generate a **fresh** one — not your local key |
| `DB_HOST` | `localhost` | usually `localhost` on cPanel |
| `DB_PORT` | `3306` | |
| `DB_NAME` | `cpaneluser_supporthive` | cPanel prefixes the name |
| `DB_USER` | `cpaneluser_sh` | |
| `DB_PASS` | | |
| `MAIL_HOST` `MAIL_PORT` `MAIL_USER` `MAIL_PASS` `MAIL_FROM` | | optional until email is wired up |

**→ Variables** (paths, not secret):

| Variable | Example |
|---|---|
| `APP_DIR` | `/supporthive_app/` |
| `WEB_ROOT` | `/public_html/` |

Both need the leading and trailing slash.

**Settings → Environments → `production`:** add a required reviewer if you want
a manual approval gate before each deploy.

**Settings → Branches:** protect `main` — require the CI check to pass and
forbid direct pushes.

## 5. Deploying

Merging to `main` deploys. To deploy without a code change, run the
*Deploy to production* workflow manually from the Actions tab.

The workflow: builds assets → assembles the two trees → writes `.env` from
secrets → uploads both over FTPS → smoke-tests the live URL and fails if
`.env`, source files, or `.git` are readable over HTTP.

`public/build.txt` contains the deployed commit SHA — use it to confirm what is
actually live.

## 6. Database migrations

**Migrations do not run automatically.** Shared hosting has no shell, and a
half-applied schema change from a failed FTP deploy is worse than a manual step.

Either:

**A. Run the migrator locally against the production database** (needs remote
MySQL access enabled in cPanel → *Remote MySQL*, with your IP allow-listed):

```bash
cp .env .env.local.bak
# temporarily point DB_* at production
php database/migrate.php --status
php database/migrate.php
mv .env.local.bak .env      # restore immediately
```

**B. Import through phpMyAdmin:** run each pending file from
`database/migrations` in filename order, then add its filename to the
`migrations` table so the runner does not repeat it.

Always take a backup first (cPanel → *Backup* → *Download a MySQL Database
Backup*), and prefer additive changes (new nullable column) over destructive
ones so a rollback of the code does not break the live schema.

## 7. Post-deploy verification

- [ ] `https://yourdomain.com/build.txt` shows the expected commit
- [ ] Home page loads over HTTPS with no mixed-content warnings
- [ ] `https://yourdomain.com/.env` → 403/404 (the workflow checks this too)
- [ ] `https://yourdomain.com/.git/config` → 403/404
- [ ] Response headers include CSP, HSTS and `X-Content-Type-Options`
- [ ] Log in, submit a form, confirm a CSRF failure is handled gracefully
- [ ] `storage/logs` shows no new errors

## 8. Rolling back

```bash
git revert <bad-commit>
git push origin main        # the revert redeploys automatically
```

Reverting code does **not** revert a migration. Restore the database from the
backup taken before the migration if the schema is the problem.

## 9. Troubleshooting

| Symptom | Likely cause |
|---|---|
| 500 on every page | `.env` missing or unreadable in `APP_DIR`; check `storage/logs/php-error.log` |
| "Application not deployed correctly" | `APP_DIR` path wrong, or `public/index.php`'s fallback does not match the directory name |
| Unstyled page | `npm run build` output missing — check the front-end job, and that `WEB_ROOT` is right |
| 404 on every route but `/` | `mod_rewrite` off or `AllowOverride` not `All`; ask the host |
| Redirect loop | Host terminates TLS at a proxy — the `X-Forwarded-Proto` condition in `public/.htaccess` handles this; if it persists, set `trust_proxy` in `config/security.php` |
| FTP action times out | Host blocks the runner's IP, or FTPS is not enabled on port 21 |
| Sessions lost on every request | `SESSION_SECURE=true` without working HTTPS |
