# FireFunds frontend

React 18 + TypeScript + Vite, shadcn/ui (Radix + Tailwind), TanStack Query,
i18next. Setup, running and the API are described in the
[main README](../README.md).

```bash
lando npm run dev     # already running with `lando start`: https://firefunds.lndo.site
lando npm run lint
lando npx tsc --noEmit -p tsconfig.json
lando npm run build   # what the Docker image ships
```

- The app calls the API on its own origin under `/api`: Vite proxies it to the
  backend in dev, FrankenPHP serves both in production.
- Texts are in `src/i18n/locales/` (`it.json`, `en.json`): every key in both.
- Colours come from the theme (`src/lib/theme.ts`), so they work in light and
  dark mode; number and money formatting goes through `src/lib/utils.ts`.
