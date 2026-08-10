# NCDEP — Market Intelligence and Exchange (MIE) backend

Setup guide for running this Laravel API backend from a clean clone.

## 1. Prerequisites

- PHP `^8.3`
- Composer
- Docker (for MySQL)

## 2. Clone and install

```bash
git clone https://github.com/E-ugine/ncdep-mie-backend.git && cd ncdep-mie-backend
composer install
```

## 3. Environment setup

Start MySQL in Docker:

```bash
docker run --name ncdep-mie-mysql -d \
  -e MYSQL_ROOT_PASSWORD=secret \
  -e MYSQL_DATABASE=ncdep_mie \
  -p 3306:3306 \
  mysql:8
```

Create the env file and key:

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` (it defaults to SQLite — switch it to the MySQL container above):

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ncdep_mie
DB_USERNAME=
DB_PASSWORD=
```

## 4. Database setup

```bash
php artisan migrate:fresh --seed
```

This runs `DatabaseSeeder`, which chains `ReferenceDataSeeder`, `HibiscusScenarioSeeder`, and `DemoUserSeeder`. Re-running `php artisan db:seed` without `migrate:fresh` is safe (reference data uses `firstOrCreate`).

## 5. Running the app

```bash
php artisan serve
```

API is available at `http://localhost:8000`.

## 6. Demo walkthrough

```bash
php artisan mie:demo-walkthrough
```

Drives the seeded demo user (`demo@ncdep-mie.test`, PIN `1234`) through the full requirement → contract flow via real controllers/middleware.

## 7. Running tests

```bash
php artisan test
```

## 8. Environment note: enable opcache for CLI

`php artisan serve` runs through the CLI SAPI. If `opcache.enable_cli` is off, PHP recompiles the framework on every request, which is slow enough to cause timing/flakiness in dependent test suites (observed during frontend E2E testing against this backend).

In your `php.ini` (or a CLI-specific ini):

```ini
opcache.enable=1
opcache.enable_cli=1
```

Verify with:

```bash
php -i | grep opcache.enable
```
