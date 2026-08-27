# Setup local — Herd + DBngin (Windows)

Everything a new developer needs. Nothing here depends on hidden state on one machine (§5.5).

## Requirements

- **Laravel Herd** (PHP, Nginx, Composer, Node) — PHP 8.4
- **DBngin** — MySQL 9.7
- **Git**

## 1. Database

Lapispro uses MySQL, not PostgreSQL — see [ADR-0001](adr/0001-mysql-instead-of-postgresql.md) for why the spec was deviated from.

In DBngin, create (or start) a **MySQL 9.7** service. This project expects it on **port 3308**; 3306 and 3307 are other projects' services on this machine.

Then create the schema. The collation is not optional — Portuguese accents depend on it (§24.4):

```sql
CREATE DATABASE lapis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

The DBngin MySQL client lives at:

```
C:\Users\<you>\AppData\Local\com.tinyapp.DBngin\Binaries\mysql\9.7.1\bin\mysql.exe
```

```powershell
& "C:\Users\<you>\AppData\Local\com.tinyapp.DBngin\Binaries\mysql\9.7.1\bin\mysql.exe" `
    -h 127.0.0.1 -P 3308 -u root -e "CREATE DATABASE lapis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

## 2. Application

```powershell
cd d:\HERD\LAPIS
composer setup     # install deps, copy .env, generate key, migrate, npm install, npm run build
php artisan db:seed
```

Check `.env` against `.env.example` — `DB_PORT` must be **3308**.

## 3. Serving

**No `herd link` needed.** `D:\HERD` is already a parked Herd path, so the folder is served automatically:

```
http://lapis.test
```

If it does not resolve, confirm the path is parked:

```powershell
herd paths     # D:\HERD should be listed
herd park d:\HERD
```

> `herd link lapis` hangs on a parked directory. So does `herd secure lapis` in a non-interactive shell — it needs UAC to trust the certificate. For TLS, run `herd secure lapis` from an **elevated** terminal; otherwise stay on `http://`, which is what `APP_URL` is set to.

## 4. Verify the install

```powershell
php artisan test          # 68 tests must pass
composer ci:check         # lint + format + larastan + tests, exactly what CI runs
```

Then open `http://lapis.test`, register an account, and confirm a personal organization was created:

```powershell
& "...\mysql.exe" -h 127.0.0.1 -P 3308 -u root -e "SELECT name, type FROM lapis.organizations;"
```

Mail is caught by Herd — the e-mail verification link appears there.

## 5. Development

```powershell
npm run dev               # vite dev server
php artisan queue:work    # queue driver is `database`
```

## Notes

- **Tests run on SQLite in-memory**, not MySQL, for speed. CI runs the suite against MySQL 9.7 — that is where engine-specific behaviour (generated columns, `CHECK` constraints, collation) is actually covered.
- **The `laravel new` installer is broken on this machine** and is not how this project was created. See [ADR-0003](adr/0003-vue-starter-kit-from-dev-main.md).
- Real credentials live only in `.env`, which is git-ignored. `.env.example` carries illustrative values only (§5.4).
