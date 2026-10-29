# Inventory API

A JSON API for a small trading shop: products and stock, purchases from suppliers, sales to customers (on credit too), proformas, and the money moving through the shop's cash, bank and mobile-money accounts. Laravel 13, MySQL in production.

Stock and money are append-only ledgers. Stock, balances and what each customer owes are always calculated from them, and mistakes are corrected by voiding or reversing with a reason, never by editing. The full design and the reasons behind it are in [docs/design.md](docs/design.md).

The web app (React) lives in its own repository and logs in with cookie sessions; mobile apps and Postman use tokens.

## Run it locally

Needs PHP 8.5 with the `gd`, `intl`, `zip` and `pdo_sqlite` extensions, and Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed          # demo data: owner@example.com and staff@example.com, password "password"
php artisan storage:link
php artisan serve                   # http://localhost:8000
```

## Commands you will use

| Command | What it does |
|---------|--------------|
| `php artisan test --compact` | Run the tests. |
| `docker compose up -d mysql-test` then `php artisan test --compact --group=mysql` | Run the concurrency tests on a real MySQL. |
| `vendor/bin/pint` | Format the code. |
| `php artisan migrate:fresh --seed` | Reset the local database to the demo data. |
| `php artisan inventory:verify` | Recalculate everything from the ledgers and list any inconsistency. |
| `php artisan inventory:create-owner` | Create a user with every permission (the first user on a new install). |
| `php artisan backup:run` / `backup:list` | Make a backup now / see the backups and whether they are healthy. |

## Documentation

- [docs/design.md](docs/design.md): data model, business rules, permissions, API overview.
- [docs/deployment.md](docs/deployment.md): setting up the server, updating, and restoring a backup.
- API reference: run `php artisan serve` and open http://localhost:8000/docs/api (local only).
- [docs/postman](docs/postman): a Postman collection that walks through every feature on the demo data.

Every push runs the code style check and both test suites on GitHub Actions.
