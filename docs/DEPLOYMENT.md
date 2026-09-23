# Deployment

Local (XAMPP) → GitHub → Hostinger shared hosting (hPanel) over SSH.

```
  localhost/XAMPP          GitHub                     Hostinger host
  ───────────────          ──────                     ──────────────
  feat/ branch    push ->  CI (lint, PHPStan,
                           tests, build, secrets)
       │                          │
       └── PR ──> main ──> Deploy workflow ──SSH──>   domains/<site>/supporthive_app/
                                              │                        (code, .env)
                                              └────>  domains/<site>/public_html/
                                                                       (public/)
                                   │
                                   └── smoke test: 200 OK + nothing sensitive readable
```

Nothing is ever uploaded by hand. Production is always exactly what is on `main`.

---

## 1. Why the two-directory layout

Hostinger serves only the `public_html` folder inside each site directory. If
the whole project lived there, a single PHP misconfiguration (a bad upgrade,
`.htaccess` ignored, the PHP handler disabled) would expose `.env`, your source
and your logs as plain text.

Hostinger's layout gives us a natural hiding place. Each site lives at
`~/domains/<site>/`, and only `public_html` inside it is served, so a sibling
folder is private by construction:

```
~/domains/supporthive.example/
|-- public_html/        <- WEB_ROOT, served over HTTP
`-- supporthive_app/    <- APP_DIR, never served
```

| Uploaded to | Contents | Web-reachable |
|---|---|---|
| `/domains/<site>/supporthive_app/` | `app/`, `config/`, `routes/`, `database/`, `scripts/`, `storage/`, `.env` | no |
| `/domains/<site>/public_html/` | `index.php`, `assets/`, `uploads/`, `.htaccess`, `robots.txt` | yes |

`public/index.php` looks for `../app/bootstrap.php` first (the local XAMPP
layout); failing that it walks up to three directories looking for
`supporthive_app/app/bootstrap.php`. The same file therefore works locally, on
a primary domain and on an addon site, with no per-host edits.

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

## 3. One-time host setup (Hostinger / hPanel)

1. **Add the site.** hPanel → *Websites* → **Add Website**, or point an existing
   domain at the account. Hostinger creates `~/domains/<site>/public_html/`.
2. **Set the PHP version.** hPanel → *Advanced* → **PHP Configuration** → select
   the site → PHP **8.3**. Under *PHP extensions* confirm `pdo_mysql`,
   `mbstring`, `fileinfo` and `openssl` are enabled.
3. **Create the application directory.** *Files* → **File Manager** → open
   `domains/<site>/` → **New Folder** → `supporthive_app`. It must sit **beside**
   `public_html`, never inside it. Leave it empty; the pipeline fills it.
4. **Create the database.** hPanel → *Databases* → **MySQL Databases**. Note the
   generated names — Hostinger prefixes them (`u123456789_supporthive`).
   Hostinger grants its database user full rights on its own database; that is
   platform behaviour and cannot be narrowed from hPanel.
5. **SSH.** *Advanced* → **SSH Access**. Note the host and port (Hostinger
   commonly uses 65002), and add the deploy key's public half. Do **not** use
   the per-site FTP account: it is chrooted to that site's `public_html` and
   therefore cannot write the application directory at all.
6. **SSL.** *Security* → **SSL** → install and verify the certificate for the new
   site, and wait for it to go active. The app forces HTTPS, so deploying before
   the certificate is live produces a redirect loop.

> **Why SSH and not FTP:** Hostinger's per-site FTP accounts are chrooted to
> their own `public_html`, so they cannot place code outside the web root. SSH
> can, and it also lets the pipeline run migrations remotely.

## 4. GitHub configuration

> Step-by-step version with the exact UI paths: [GITHUB-SETUP.md](GITHUB-SETUP.md).

**Settings → Secrets and variables → Actions → Secrets:**

| Secret | Example | Notes |
|---|---|---|
| `SSH_HOST` | `45.137.159.36` | from hPanel → Advanced → SSH Access |
| `SSH_PORT` | `65002` | Hostinger rarely uses 22 |
| `SSH_USER` | `u400948127` | the account user |
| `SSH_PRIVATE_KEY` | | the whole deploy key file, BEGIN/END lines included |
| `SSH_KNOWN_HOSTS` | | output of `ssh-keyscan -p <port> <host>` |
| `APP_URL` | `https://yourdomain.com` | no trailing slash |
| `APP_KEY` | `base64:…` | generate a **fresh** one — not your local key |
| `DB_HOST` | `localhost` | `localhost` on Hostinger |
| `DB_PORT` | `3306` | |
| `DB_NAME` | `u123456789_supporthive` | Hostinger prefixes the name |
| `DB_USER` | `u123456789_sh` | |
| `DB_PASS` | | |
| `MAIL_HOST` `MAIL_PORT` `MAIL_USER` `MAIL_PASS` `MAIL_FROM` | | optional until email is wired up |

**→ Variables** (paths, not secret):

| Variable | Example |
|---|---|
| `APP_DIR` | `/home/<user>/domains/<site>/supporthive_app` |
| `WEB_ROOT` | `/home/<user>/domains/<site>/public_html` |
| `DEPLOY_ENABLED` | `true` |
| `RUN_MIGRATIONS` | `true` to migrate on every deploy (optional) |

Use **absolute** paths: SSH has no chroot making them relative.
`DEPLOY_ENABLED` gates the whole deploy job — while it is unset the job is
skipped, so `main` stays green before hosting exists. The job also refuses to
run if `APP_DIR` points inside the web root.

**Settings → Environments → `production`:** add a required reviewer if you want
a manual approval gate before each deploy.

**Settings → Branches:** protect `main` — require the CI check to pass and
forbid direct pushes.

## 5. Deploying

Merging to `main` deploys, once `DEPLOY_ENABLED` is `true`. To deploy without a code change, run the
*Deploy to production* workflow manually from the Actions tab.

The workflow: builds assets → assembles the two trees → writes `.env` from
secrets → verifies the SSH connection and that both remote directories exist →
rsyncs each tree to its destination → optionally migrates → smoke-tests the
live URL and fails if `.env`, source files, or `.git` are readable over HTTP.

`public/build.txt` contains the deployed commit SHA — use it to confirm what is
actually live.

## 6. Database migrations

Migrations run automatically **only** when the repository variable
`RUN_MIGRATIONS` is `true`. It is off by default: a half-applied schema change
is worse than a manual step, so you opt in once you trust the pipeline.

With `RUN_MIGRATIONS=true` the deploy runs `database/migrate.php` over SSH
after the upload. To do it by hand instead:

**A. Over SSH on the host** (simplest now that SSH is available):

```bash
ssh -i ~/.ssh/supporthive_deploy -p <SSH_PORT> <SSH_USER>@<SSH_HOST>
cd domains/<site>/supporthive_app
php database/migrate.php --status
php database/migrate.php
```

Or remotely from your machine:

**A. Run the migrator locally against the production database** (needs remote
MySQL access enabled in hPanel → *Databases* → **Remote MySQL**, with your IP added):

```bash
cp .env .env.local.bak
# temporarily point DB_* at production
php database/migrate.php --status
php database/migrate.php
mv .env.local.bak .env      # restore immediately
```

**B. Import through phpMyAdmin** (hPanel → *Databases* → phpMyAdmin)**:** run each pending file from
`database/migrations` in filename order, then add its filename to the
`migrations` table so the runner does not repeat it.

Always take a backup first (hPanel → *Files* → **Backups**), and prefer additive changes (new nullable column) over destructive
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
| SSH connection refused | Wrong `SSH_PORT` (Hostinger often 65002, not 22), or the deploy public key is not in the host's authorized keys |
| Sessions lost on every request | `SESSION_SECURE=true` without working HTTPS |
