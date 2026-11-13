# turnario-app-turnario-site

Static public site of Turnario: home, funzioni, prezzi, supporto, one landing per corps and the legal texts, in Italian (root) and English (`/en`). Built with Twig and published on GitHub Pages at **www.turnario.app**.

What is *not* here (it needs the backend and stays in `turnario-api`, on `turnario.app`): the waiting list (`/in-arrivo` and its email links), account deletion (`/delete-account`), the Universal Link / App Link pages (`/d/…`, `/invite/…`, `/verify-email`, `/reset-password`…), `/.well-known/*`, `/media/*`, Stripe returns and the API (`api.turnario.app`). The site links to them with absolute URLs (`dynamicUrl` in `config/*.json`).

## Build

```sh
composer install
php build/build.php            # production → dist/
php build/build.php --env=qa   # QA host, noindex robots.txt
```

- `templates/` – Twig templates (same ones the API used to render) · `translations/` – `web` and `enums` catalogs
- `config/prod.json`, `config/qa.json` – public host, web app, stores, mailboxes, legal version
- `data/catalog.json` – **snapshot of the Premium catalog** (prices, offers, features, corps). It must match `GET /api/plans` and the enums of `turnario-api`: when a price changes, change it here too and merge to `main`.
- `public/` – copied as is (store badges) · `dist/` – output (ignored)

Pull requests build the site and check it; every push to `main` deploys.

## Deploy (once)

1. Repository **public** (GitHub Pages is not available on private repos of a Free organization).
2. Settings → Pages → Source: **GitHub Actions**; custom domain `www.turnario.app`, *Enforce HTTPS*.
3. DNS: see `docs/DNS.md`.
