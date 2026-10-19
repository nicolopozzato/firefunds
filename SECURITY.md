# Security policy

FireFunds holds people's financial data, so security reports are taken seriously.

## Reporting a vulnerability

**Please do not open a public issue.** Report it privately through GitHub: on the [Security tab](https://github.com/nicolopozzato/firefunds/security) of this repository, choose **Report a vulnerability**.

Include what you found, how to reproduce it, the version (shown at the bottom of every page) and what an attacker could do with it. You will get an answer within a week. Once a fix is released, you will be credited in the release notes, unless you prefer not to be.

## Supported versions

Only the latest release gets security fixes. Keep your instance up to date:

```bash
docker compose pull && docker compose up -d
```

## Running it safely

- Generate every secret in `.env` with `openssl rand -hex 32`, each with its own value, and never reuse the examples.
- Keep the app behind a reverse proxy with HTTPS. Out of the box it listens only on `127.0.0.1`.
- Close registrations (Admin → Settings) once your users have signed up.
- Back up `./data` and keep the backups as private as the data itself.
