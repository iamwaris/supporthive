# Connecting the repository and turning on deployment

One-time setup. Everything after this is `git push`.

Deployment runs over **SSH** (rsync, falling back to tar), not FTP. That matters
for more than encryption: an SSH session can write anywhere in your home
directory, so the application code stays outside the web root — which a
per-site FTP account, chrooted to its own `public_html`, cannot do.

---

## 1. Point the local repository at GitHub

```bash
cd C:/xampp/htdocs/SupportHive

git remote add origin git@github.com:iamwaris/supporthive.git
git push -u origin main
```

Confirm nothing sensitive was pushed:

```bash
git ls-files | grep -E '^\.env$|node_modules|^vendor/'   # must print nothing
```

## 2. Create the deploy key and authorise it on the host

Use a **dedicated** key, never your personal one: it can be revoked on its own,
and it never leaves GitHub's secret store after you paste it in.

```bash
ssh-keygen -t ed25519 -f ~/.ssh/supporthive_deploy -N "" -C "github-actions-supporthive-deploy"
```

1. **Public half → the host.** hPanel → *Advanced* → **SSH Access** → paste the
   contents of `~/.ssh/supporthive_deploy.pub` into the SSH keys section.
2. **Private half → GitHub.** The `SSH_PRIVATE_KEY` secret below. Paste the
   whole file including the `-----BEGIN OPENSSH PRIVATE KEY-----` and
   `-----END OPENSSH PRIVATE KEY-----` lines.

Then pin the host's fingerprint so a hijacked DNS record cannot receive your
`.env` (replace host and port with the values from the SSH Access page):

```bash
ssh-keyscan -p <SSH_PORT> <SSH_HOST>
```

The output lines are the `SSH_KNOWN_HOSTS` secret. Verify the connection works
before configuring anything else:

```bash
ssh -i ~/.ssh/supporthive_deploy -p <SSH_PORT> <SSH_USER>@<SSH_HOST> "pwd && ls domains"
```

## 3. Secrets

**Settings → Secrets and variables → Actions → New repository secret**

| Secret | Value |
|---|---|
| `SSH_HOST` | from hPanel → Advanced → SSH Access (an IP or hostname) |
| `SSH_PORT` | Hostinger commonly uses `65002`, not 22 — read it from that page |
| `SSH_USER` | your account user, e.g. `u400948127` |
| `SSH_PRIVATE_KEY` | the entire contents of `~/.ssh/supporthive_deploy` |
| `SSH_KNOWN_HOSTS` | the `ssh-keyscan` output from step 2 |
| `APP_URL` | `https://yoursite.com` — no trailing slash |
| `APP_KEY` | run `php scripts/genkey.php`; use a **fresh** value, not your local one |
| `DB_HOST` | `localhost` |
| `DB_PORT` | `3306` |
| `DB_NAME` | Hostinger prefixes it, e.g. `u400948127_supporthive` |
| `DB_USER` | e.g. `u400948127_hive` |
| `DB_PASS` | that user's password |
| `MAIL_HOST` `MAIL_PORT` `MAIL_USER` `MAIL_PASS` `MAIL_FROM` | optional until email is wired up |

## 4. Variables

**Same page → Variables tab.** These are paths and switches, not secrets.

| Variable | Value |
|---|---|
| `APP_DIR` | `/home/<user>/domains/<site>/supporthive_app` |
| `WEB_ROOT` | `/home/<user>/domains/<site>/public_html` |
| `DEPLOY_ENABLED` | `true` — **set this last** |
| `RUN_MIGRATIONS` | `true` to migrate on every deploy (optional) |
| `PHP_BIN` | only if plain `php` on the host is the wrong version, e.g. `/opt/alt/php83/usr/bin/php` |

Use **absolute** paths over SSH — unlike FTP, there is no chroot making them
relative. `APP_DIR` must sit beside `public_html`, never inside it; the deploy
job refuses to run if you point it into the web root.

`DEPLOY_ENABLED` is the master switch. While it is unset the deploy job skips
entirely, so pushes to `main` stay green. Set it once everything above is filled
in and the application directory exists on the host.

`RUN_MIGRATIONS` is deliberately separate. Leave it off for the first deploy,
then turn it on once you have seen the pipeline work — and take a database
backup before any destructive schema change.

## 5. Protect `main`

**Settings → Rules → Rulesets → New branch ruleset**, targeting `main`:

- Require a pull request before merging
- Require status checks: `PHP checks`, `Front-end build`, `Secret scan`
- Block force pushes

`main` is what deploys, so anything that lands there reaches production.

## 6. Optional: manual approval per deploy

**Settings → Environments → New environment → `production`** → tick *Required
reviewers* and add yourself. The deploy job then waits for your click. Worth
enabling until you have watched a few deploys succeed.

## 7. First deploy

1. In hPanel → *Files* → **File Manager**, open `domains/<site>/` and create the
   folder `supporthive_app`, **beside** `public_html`. The pipeline does not
   create it — that is deliberate, so a mistyped path fails loudly instead of
   scattering directories across your account.
2. Set `DEPLOY_ENABLED` to `true`.
3. Run **Actions → Deploy to production → Run workflow**, rather than pushing.
   A manual run proves the pipeline without coupling it to a code change.

The workflow verifies the connection and that both directories exist *before*
transferring anything, then smoke-tests the live site and fails if `.env`, your
source, or `.git` turn out to be readable over HTTP.

## 8. Migrations on the first deploy

With SSH available you can simply run them yourself once:

```bash
ssh -i ~/.ssh/supporthive_deploy -p <SSH_PORT> <SSH_USER>@<SSH_HOST>
cd domains/<site>/supporthive_app
php database/migrate.php --status
php database/migrate.php
```

After that, setting `RUN_MIGRATIONS=true` handles it on every deploy.

## 9. Day-to-day

```bash
git checkout -b feat/login
# ...work...
composer check && npm run build
git push -u origin feat/login
# open a PR, fill in the security checklist, wait for green CI, merge
```

Merging to `main` deploys.
