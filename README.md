# SupportHive

Core PHP 8.3 application — no framework, MVC structure, MySQL via PDO,
Tailwind + Alpine front end. Deploys from `main` to Hostinger shared hosting
through GitHub Actions.

## Quick start (XAMPP)

```bash
cp .env.example .env
C:/xampp/php/php.exe scripts/genkey.php     # paste into APP_KEY
C:/xampp/mysql/bin/mysql.exe -u root -e "CREATE DATABASE supporthive CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
C:/xampp/php/php.exe database/migrate.php

npm install && npm run vendor && npm run build
composer install

C:/xampp/php/php.exe scripts/preflight.php
```

Then open <http://localhost/SupportHive/public/>.

Full instructions, including the virtual-host setup: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Layout

```
app/
  Core/          framework: routing, DB, session, CSRF, validation, auth, uploads
  Controllers/   thin: validate -> delegate -> respond
  Models/        data access; all SQL lives here
  Views/         templates (layouts/, pages/, errors/)
  Helpers/       global functions, incl. e()
config/          app, database, session, security, uploads
routes/          web.php - the complete route table
database/
  migrations/    forward-only .sql files
  migrate.php    CLI runner
public/          THE ONLY WEB-REACHABLE DIRECTORY
  index.php      single front controller
  assets/        compiled CSS, app JS, vendored libraries
  uploads/       user files; execution blocked by .htaccess
storage/logs/    application and security logs
scripts/         genkey.php, preflight.php, vendor-assets.mjs
docs/            plan, tracker, security, deployment, design system
```

## Documentation

| Document | Contents |
|---|---|
| [CLAUDE.md](CLAUDE.md) | **Engineering rules** — read before writing code |
| [docs/PLAN.md](docs/PLAN.md) | Product definition, milestones, decisions, risks |
| [docs/TRACKER.md](docs/TRACKER.md) | Task board — update it with your work |
| [docs/SECURITY.md](docs/SECURITY.md) | Security mechanisms and review checklists |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Local setup, CI/CD, migrations, rollback |
| [docs/GITHUB-SETUP.md](docs/GITHUB-SETUP.md) | One-time: remote, secrets, variables, branch protection |
| [docs/DESIGN-SYSTEM.md](docs/DESIGN-SYSTEM.md) | Tokens, primitives, UI conventions, a11y |

## Commands

| Command | Does |
|---|---|
| `composer check` | Coding standards + static analysis + tests |
| `composer cs:fix` | Auto-fix PSR-12 violations |
| `npm run dev` | Watch and rebuild Tailwind |
| `npm run build` | Minified production CSS |
| `npm run vendor` | Copy JS libraries into `public/assets/vendor` |
| `php database/migrate.php` | Apply pending migrations (`--status` to inspect) |
| `php scripts/preflight.php` | Environment and security sanity check |
| `php scripts/genkey.php` | Generate an `APP_KEY` |

## Workflow

Branch → build → `composer check` → PR (security checklist) → CI green → merge
to `main` → automatic deploy → verify `build.txt`.
