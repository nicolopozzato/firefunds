# FireFunds

[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

**Know where your money goes.**

FireFunds is a self-hosted personal finance app. You record where your money is (bank accounts, cards, cash, brokers), who you pay and who pays you, and FireFunds shows you balances, spending by category, savings rate, investments and net worth over time. It runs on your own server as a single Docker image, works in the browser and installs on your phone as an app (PWA). Your data never leaves your machine.

It uses double-entry bookkeeping, like [Firefly III](https://www.firefly-iii.org), which inspired it, with first-class support for investments: ETF and stock positions, daily prices, gains and the estimated tax if you sold today.

> **Status:** personal project, used daily by its author and under active development. Only **EUR** is supported for now (see [Limitations](#limitations)). Interface in English and Italian.

**Italiano:** [la descrizione e l'installazione in italiano](#in-italiano) sono in fondo alla pagina.

## Screenshots

<table>
  <tr>
    <td width="50%"><img src="screenshots/dashboard-light.png" alt="Dashboard in the light theme: income, expenses, savings rate, net worth and charts"><br><sub>Dashboard</sub></td>
    <td width="50%"><img src="screenshots/dashboard-dark.png" alt="The same dashboard in the dark theme"><br><sub>Dashboard, dark theme</sub></td>
  </tr>
  <tr>
    <td width="50%"><img src="screenshots/investments.png" alt="Investments overview: portfolio value, gain and positions"><br><sub>Investments overview</sub></td>
    <td width="50%"><img src="screenshots/report-income-expenses.png" alt="Income and expenses report with a column per month"><br><sub>Income and expenses report</sub></td>
  </tr>
  <tr>
    <td colspan="2"><img src="screenshots/report-net-worth.png" alt="Net worth report: net worth over the year and every asset at the end of each month"><br><sub>Net worth report</sub></td>
  </tr>
</table>

<table>
  <tr>
    <td width="33%"><img src="screenshots/mobile-dashboard.png" alt="Dashboard on a phone"><br><sub>Phone: dashboard</sub></td>
    <td width="33%"><img src="screenshots/mobile-transactions.png" alt="Transactions grouped by day on a phone"><br><sub>Phone: transactions</sub></td>
    <td width="33%"><img src="screenshots/mobile-investments.png" alt="Investments on a phone"><br><sub>Phone: investments</sub></td>
  </tr>
</table>

_Fictional sample data._

## What it does

### Money in, money out

Every transaction moves money between two accounts, so the books always balance:

- **Asset accounts** are where your money is: checking, savings, cash, credit card, broker.
- **Expense accounts** are who you pay (supermarket, landlord, online shop…), **revenue accounts** who pays you (employer, refunds…).
- **Categories** say why (groceries, housing, travel…); **tags** group anything else (a holiday, a project).

```
€67.58 at the supermarket

Checking account  -67.58 €
Supermarket       +67.58 €     category: Groceries
```

Withdrawals, deposits and transfers can be created, edited, cloned and deleted, each with its own change history. A side can be left empty (a gift you received, cash you gave away). Nothing is ever really deleted: deleted data stays in the database, out of sight.

### Dashboard

For any period (this month, this year, a range of your choice): income, expenses, balance and savings rate; investments and their market gain; net worth and how it changed. Charts of balances or instrument prices, spending by category and by expense account, and a box per chosen account with its latest transactions.

### Investments

- Instruments (ETF, stocks, bonds, funds, crypto…) with a Yahoo Finance ticker; **daily closing prices** fetched automatically four times a day and kept for every day, weekends included.
- **BUY / SELL** trades on broker accounts, with fees and taxes. The amount is what the broker actually charged (a 50.00 monthly plan that bought 0.300987 units at 166.12, not 49.99996).
- A broker account holds both **cash** and **positions**: value, gain, average cost (average-cost method), weight, holding period.
- **Overview** across every broker: portfolio value, invested capital, gain, share of net worth, one row per instrument even if held on two brokers, contributions by month.
- **Estimated capital gains tax** if you sold today, at your rate (26% by default) or the instrument's own (e.g. 12.5% on government bonds, or worked out from an ETF's white-list share).

### Reports

- **Income and expenses**: a column per month or per year over any range, optionally compared with the range before or the year before; income, expenses, savings, net worth, and a category × month table where every cell opens its transactions.
- **Net worth**: a year at a time, every account and instrument at the end of each month, and each month's change split into what you saved and what the market did.

### Everything else

- Multi-user, every record scoped to its owner; the first user to register is the admin and can close registrations.
- Light and dark theme (or follow the device), Italian and English, your own number format and timezone.
- Search across descriptions, notes, accounts, categories and tags.
- JWT login with single-use refresh tokens (a replayed one signs the session out), logout and password changes that end sessions, and login throttling (5 failed attempts per email and IP lock it for up to 15 minutes).

## Installation

**Requirements:** a server with Docker and Docker Compose. One image, `ghcr.io/nicolopozzato/firefunds`, holds the whole app.

```bash
mkdir firefunds && cd firefunds
curl -O https://raw.githubusercontent.com/nicolopozzato/firefunds/main/compose.yml
curl -O https://raw.githubusercontent.com/nicolopozzato/firefunds/main/.env
```

Open `.env`, set `DOMAIN` and fill in the 3 secrets (`POSTGRES_PASSWORD`, `APP_SECRET`, `JWT_PASSPHRASE`), each with its own value from:

```bash
openssl rand -hex 32
```

Start:

```bash
docker compose up -d
```

The app is at `http://127.0.0.1:8080` (check: `curl http://127.0.0.1:8080/api/health`). Open it, register: the first user becomes the admin. Then create your accounts, a few categories, and start recording transactions; for investments, create the instruments first.

Everything FireFunds stores is in `./data` (database, login keys, scheduler state).

| Service     | What it does                                                      |
|-------------|-------------------------------------------------------------------|
| `database`  | PostgreSQL 16                                                     |
| `app`       | FrankenPHP: the web app on `/`, the API on `/api`                 |
| `scheduler` | Background jobs: the investment price fetch, at 00:10, 04:10, 08:10 and 12:10 (`TIMEZONE`) |

### HTTPS (reverse proxy)

The app only speaks plain HTTP on `127.0.0.1`. To reach it from outside, put it behind a reverse proxy. With Traefik and an existing external `proxy` network, create a `compose.override.yml` next to `compose.yml`:

```yaml
services:
  app:
    ports: !reset []
    networks:
      - default
      - proxy
    labels:
      traefik.enable: "true"
      traefik.docker.network: "proxy"
      traefik.http.routers.firefunds.rule: "Host(`${DOMAIN}`)"
      traefik.http.routers.firefunds.entrypoints: "websecure"
      traefik.http.services.firefunds.loadbalancer.server.port: "8000"

networks:
  proxy:
    external: true
```

Then `docker compose up -d` again. With another proxy (nginx, Caddy, Cloudflare Tunnel), skip the override and point it at `127.0.0.1:8080`.

Login throttling counts failed attempts by the client's address, which the app reads from `X-Forwarded-For` when the request comes from a private network (`TRUSTED_PROXIES` in `.env`). Your proxy must add the client's address to that header rather than pass on what the client sent: Traefik, Caddy and nginx with `$proxy_add_x_forwarded_for` do.

The register page is open until the first account exists, and that account is the administrator: create it before the app is reachable from the internet.

### Updating

```bash
docker compose pull && docker compose up -d
```

Database migrations run by themselves when the new version starts, so take a backup first. To stay on a known version, set `FIREFUNDS_TAG` in `.env` (e.g. `1.2.0`, or `1.2` for its latest patch) instead of `latest`.

### Backup

```bash
docker compose exec database pg_dump -U firefunds -Fc firefunds > firefunds_$(date +%F).dump
```

Keep `./data/jwt` too: without those keys everyone simply logs in again.

## Limitations

- **EUR only.** Accounts have a currency and a transaction can record a foreign amount, but nothing is converted: totals and charts add every amount as if it were in EUR. An instrument quoted in USD on a EUR broker account is therefore valued wrongly; stick to instruments quoted in EUR (e.g. `VWCE.MI`) until multi-currency lands.
- **The current price is the last close** (yesterday's at best), not a live quote.
- Selling more units than you hold is not blocked, like overdrawing a checking account.

## Roadmap

- **Multi-currency**: each instrument's quote currency, daily exchange rates, trades in a foreign currency with the rate the broker applied, everything shown in the account's currency.
- Live quotes, realized gains on sales, dividends.
- Split transactions, reconciliation.
- Imports (bank CSV, payment notifications) with proposed transactions to confirm, optionally AI-assisted classification.

## Contributing

Issues and pull requests are welcome. [CONTRIBUTING.md](CONTRIBUTING.md) explains how to run FireFunds locally (one command, with [Lando](https://lando.dev)), how the code is organized and the rules it follows.

**Tech stack:** PHP 8.4, Symfony 8.1, PostgreSQL 16, [FrankenPHP](https://frankenphp.dev); React 18, TypeScript, Vite, shadcn/ui, TanStack Query, i18next.

## Security

Please don't open a public issue for a vulnerability: see [SECURITY.md](SECURITY.md).

## In italiano

FireFunds è un'app per le finanze personali da installare sul proprio server. Registri dove sono i tuoi soldi (conti, carte, contanti, broker), chi paghi e chi ti paga, e FireFunds ti mostra saldi, spese per categoria, tasso di risparmio, investimenti e patrimonio netto nel tempo. Gira come una sola immagine Docker, si usa dal browser e si installa sul telefono come app (PWA). I tuoi dati restano sul tuo server.

Usa la partita doppia, come [Firefly III](https://www.firefly-iii.org) da cui prende ispirazione, e gestisce gli investimenti: posizioni in ETF e azioni, prezzi giornalieri, guadagni e la stima delle tasse se vendessi oggi. Interfaccia in italiano e in inglese; per ora solo in **euro**.

### Cosa fa

- **Entrate e uscite**: ogni transazione sposta denaro fra due conti (i tuoi conti, chi paghi, chi ti paga), con categorie, etichette e la cronologia delle modifiche. Niente viene mai cancellato davvero.
- **Dashboard** per qualsiasi periodo: entrate, uscite, risparmio, investimenti, patrimonio netto e grafici.
- **Investimenti**: strumenti con ticker Yahoo Finance e prezzi di chiusura scaricati in automatico, acquisti e vendite con commissioni e tasse, posizioni con prezzo medio di carico, panoramica su tutti i broker e la stima del capital gain (26% di default, o l'aliquota del singolo strumento, es. 12,5% sui titoli di Stato).
- **Report**: entrate e uscite per mese o per anno con confronto, e il patrimonio netto mese per mese.
- Più utenti, ognuno con i suoi dati; il primo che si registra è l'amministratore. Tema chiaro e scuro, formato dei numeri e fuso orario a scelta, ricerca.

### Installazione

Serve un server con Docker e Docker Compose.

```bash
mkdir firefunds && cd firefunds
curl -O https://raw.githubusercontent.com/nicolopozzato/firefunds/main/compose.yml
curl -O https://raw.githubusercontent.com/nicolopozzato/firefunds/main/.env
```

Apri `.env`, imposta `DOMAIN` e compila i 3 segreti (`POSTGRES_PASSWORD`, `APP_SECRET`, `JWT_PASSPHRASE`), ognuno con un valore diverso generato da `openssl rand -hex 32`. Poi avvia:

```bash
docker compose up -d
```

L'app risponde su `http://127.0.0.1:8080`. Aprila e registrati: il primo utente diventa l'amministratore, quindi crealo **prima** di rendere l'app raggiungibile da internet. Tutto quello che FireFunds salva è in `./data`.

**HTTPS:** l'app parla solo HTTP su `127.0.0.1`; mettila dietro un reverse proxy (Traefik, Caddy, nginx, Cloudflare Tunnel). L'esempio per Traefik è nella sezione [HTTPS](#https-reverse-proxy). Il proxy deve aggiungere l'indirizzo del client all'header `X-Forwarded-For`, che serve al blocco dei tentativi di accesso falliti.

**Aggiornare:** `docker compose pull && docker compose up -d`. Le migrazioni del database partono da sole all'avvio, quindi fai prima un backup:

```bash
docker compose exec database pg_dump -U firefunds -Fc firefunds > firefunds_$(date +%F).dump
```

### Limiti

- **Solo euro**: le valute ci sono, ma nulla viene convertito. Usa strumenti quotati in euro (es. `VWCE.MI`).
- Il prezzo attuale di uno strumento è l'ultima chiusura, non una quotazione in tempo reale.

Segnalazioni e contributi sono benvenuti, in italiano o in inglese: vedi [CONTRIBUTING.md](CONTRIBUTING.md). Per le vulnerabilità, [SECURITY.md](SECURITY.md).

## License

[GNU AGPL v3.0](LICENSE): you can use, modify and self-host FireFunds freely; if you offer a modified version as a service, you must publish your changes under the same license.
