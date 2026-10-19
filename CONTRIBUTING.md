# Contributing to FireFunds

Thanks for wanting to help. Bug reports, ideas and pull requests are all welcome.

- **Bugs and ideas**: open an [issue](https://github.com/nicolopozzato/firefunds/issues). For a bug, say what you did, what you expected and what happened instead, with the version (bottom of every page).
- **Security problems**: not in an issue, see [SECURITY.md](SECURITY.md).
- **Pull requests**: for anything bigger than a small fix, open an issue first so we can agree on the approach. This repository receives one commit per release: a merged pull request is applied to the development branch and ships with the next release, with you credited.

## Running it locally

You only need Docker and [Lando](https://lando.dev): PHP, Node and PostgreSQL all run in containers.

```bash
git clone https://github.com/nicolopozzato/firefunds.git
cd firefunds
lando start
```

- App, with hot reload: https://firefunds.lndo.site
- Backend directly (Symfony profiler): https://api.firefunds.lndo.site/_profiler
- Database from the host: `localhost:54320`, user and password `app`, database `firefunds`

On start the backend installs its dependencies, generates a JWT keypair and runs the migrations. Register a user and you are in. For sample data, `python3 scripts/demo/seed.py https://firefunds.lndo.site` fills an **empty** instance with a fictional user (`demo@example.com` / `Demo-password-123`) and a year of transactions and investments.

The services are defined in [.lando.yml](.lando.yml) as plain Docker Compose config; the backend image is the `dev` target of the [Dockerfile](Dockerfile), and every service's configuration (PHP ini, Caddyfile, entrypoint) is in [docker/](docker/).

### Everyday commands

```bash
lando console <command>          # Symfony console, e.g. lando console make:migration
lando composer <command>
lando phpunit                    # backend tests, on a separate firefunds_test database
lando npm <command>              # in frontend/, e.g. lando npm run lint
lando npx tsc --noEmit -p tsconfig.json
lando npm test                   # frontend unit tests (Vitest)
lando psql                       # psql on the dev database
lando db-dump                    # → var/dumps/firefunds_<date>.dump
lando db-import var/dumps/<file> # replace the dev database (.dump, .sql, .sql.gz)
```

The first time you run the tests, create the test database:

```bash
lando console doctrine:database:create --env=test
lando console doctrine:migrations:migrate --env=test -n
```

Xdebug is installed but off: `XDEBUG_MODE=debug lando rebuild -s backend -y` turns it on.

## How the code is organized

```
backend/    Symfony API, one folder per area under src/ (Account, Transaction,
            Investment, Report, Dashboard, Auth, …): Controller, DTO, Service
frontend/   React app: src/pages, src/components, src/lib (API client,
            formatting, theme), src/i18n/locales
docker/     configuration of each service (FrankenPHP, PostgreSQL scripts)
Dockerfile  the image: `dev` for Lando, `prod` for releases
compose.yml what self-hosters run
```

In production one FrankenPHP container serves the built React app on `/` and the API on `/api`. In development Vite serves the app and proxies `/api` to the backend, so the app always calls the API on its own origin.

## Rules the code follows

These are product decisions, not just style. A pull request that breaks one will be asked to change.

- **Nothing is ever physically deleted.** Every delete is a soft delete (`SoftDeletable`, `deleted_at`). Deleting something other data depends on handles that data explicitly and tells the user first: an account's transactions are moved or deleted with it, a category is taken off its transactions. A test fails if application code removes rows.
- **A transaction may have no account on one side** (income from nobody in particular, money given away), never both. Withdrawals go from an asset account to an expense account or nowhere; deposits come from a revenue account or nowhere into an asset account.
- **Thin controllers.** Calculations belong in services, and a list never runs one query per row (`OpenPositionService` shows the pattern).
- **Time.** System timestamps (created/updated, audit logs) are UTC instants. A transaction's date and time are stored as written where it happened, with that place's timezone (`App\Entity\OccursLocally`): its day never depends on who looks at it, so don't convert it in queries. "Today" follows the user's timezone; the price calendar and the scheduled price fetch use `APP_TIMEZONE`.
- **Formatting.** Numbers follow the user's number format, dates the interface language; everything goes through `frontend/src/lib/utils.ts`.
- **Colours come from the theme** (`frontend/src/lib/theme.ts`), never straight from Tailwind's palette, so they work in light and dark mode: `positive`, `negative`, `info`, `invest`, `warning`, chart series `--series-1`…`--series-8`. Check dark mode too.
- **Every text in both languages.** New UI text goes in `frontend/src/i18n/locales/it.json` and `en.json`.
- **API errors are translated, never shown as the backend's text.** An error the user can meet is an `ApiException` with an `ErrorCode`; validation errors get a code per field (`ViolationCode`). The frontend shows `errors.api.<code>` / `errors.validation.<code>`. A new code needs its text in both languages, or a test fails.
- **Schema changes** go through Doctrine migrations (`lando console make:migration`), applied to the test database too.

## Before opening a pull request

```bash
lando phpunit
lando npx tsc --noEmit -p tsconfig.json
lando npm run lint
lando npm test
lando npm run build
```

All five must pass; the same checks run on every push and pull request (`.github/workflows/checks.yml`). Add a test for what you changed: an HTTP request for an endpoint, a service call for a service, a `*.test.ts` next to a pure frontend module (Vitest). If you touched the UI, look at it in light and dark mode and on a phone-sized window.

By contributing you agree that your contribution is licensed under the [AGPL-3.0](LICENSE).
