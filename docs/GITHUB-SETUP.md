# Connecting the repository and turning on deployment

One-time setup. Everything after this is `git push`.

---

## 1. Point the local repository at GitHub

The repository is already initialised and has three commits on `main`.

```bash
cd C:/xampp/htdocs/SupportHive

git remote add origin https://github.com/<you>/<repo>.git
git push -u origin main
```

If the remote already has commits (a README created with the repo), rebase
onto them rather than force-pushing:

```bash
git pull --rebase origin main
git push -u origin main
```

Confirm the push did **not** include anything it shouldn't:

```bash
git ls-files | grep -E '^\.env$|node_modules|^vendor/'   # must print nothing
```

## 2. Secrets

**Settings → Secrets and variables → Actions → New repository secret**

| Secret | Value |
|---|---|
| `FTP_SERVER` | `ftp.yourdomain.com` — hostname only, no `ftp://` |
| `FTP_USERNAME` | the Hostinger FTP account (hPanel → Files → FTP Accounts) |
| `FTP_PASSWORD` | that account's password |
| `APP_URL` | `https://yourdomain.com` — no trailing slash |
| `APP_KEY` | run `php scripts/genkey.php` and use a **fresh** value, not your local one |
| `DB_HOST` | `localhost` on Hostinger |
| `DB_PORT` | `3306` |
| `DB_NAME` | Hostinger prefixes it, e.g. `u123456789_supporthive` |
| `DB_USER` | e.g. `u123456789_sh` |
| `DB_PASS` | that user's password |
| `MAIL_HOST` `MAIL_PORT` `MAIL_USER` `MAIL_PASS` `MAIL_FROM` | optional until email is wired up — leave unset for now |

## 3. Variables

**Same page → Variables tab.** These are paths, not secrets.

| Variable | Value |
|---|---|
| `APP_DIR` | `/domains/<site>/supporthive_app/` |
| `WEB_ROOT` | `/domains/<site>/public_html/` |
| `DEPLOY_ENABLED` | `true` |

Paths are relative to the FTP account's root, which on Hostinger is the home
directory — hence the leading `/domains/`. Both need a leading **and** trailing
slash. `APP_DIR` must sit *beside* `public_html`, not inside it — that is the
whole point of the two-directory layout, and the deploy job refuses to run if
you point it inside the web root.

`DEPLOY_ENABLED` is the master switch. **Leave it unset until everything above
is filled in and the application directory exists on the host** — the deploy
job skips entirely while it is off, so pushes to `main` stay green. Set it to
`true` when you are ready for the first real deploy.

## 4. Protect `main`

**Settings → Rules → Rulesets → New branch ruleset** (or Branches → Add rule on
older UIs), targeting `main`:

- Require a pull request before merging
- Require status checks to pass → select `PHP checks`, `Front-end build`,
  `Secret scan`
- Block force pushes

`main` is what deploys, so anything that lands there reaches production.

## 5. Optional: manual approval before each deploy

**Settings → Environments → New environment → `production`** → tick *Required
reviewers* and add yourself. The deploy job then waits for your click.

Worth enabling until you've watched a few deploys succeed.

## 6. First deploy

Before pushing to `main`, create the application directory on the host (hPanel →
Files → File Manager → `domains/<site>/` → **New Folder** → `supporthive_app`,
beside `public_html`). The pipeline will not create it for you.

Then set `DEPLOY_ENABLED` to `true` and push:

```bash
git push origin main
```

Watch **Actions → Deploy to production**. The final step smoke-tests the live
site and fails loudly if `.env`, your source, or `.git` turn out to be readable
over HTTP.

## 7. After the first deploy

Migrations do not run automatically — see
[DEPLOYMENT.md §6](DEPLOYMENT.md#6-database-migrations). For the first deploy,
import both files from `database/migrations` through phpMyAdmin, then insert
their filenames into the `migrations` table so the runner won't repeat them.

Verify: `https://yourdomain.com/build.txt` should show the deployed commit SHA.

## 8. Day-to-day

```bash
git checkout -b feat/login
# ...work...
composer check && npm run build
git push -u origin feat/login
# open a PR, fill in the security checklist, wait for green CI, merge
```

Merging to `main` deploys.
