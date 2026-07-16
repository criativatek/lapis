# ADR-0003 — Scaffolded from `laravel/vue-starter-kit` `dev-main`, not the Laravel installer

- **Status:** Accepted
- **Date:** 2026-07-17

## Context

§5.2 specifies Laravel 13.x, Vue 3 + TypeScript, Inertia, Vite and Tailwind. §5.1 requires Laravel Herd locally.

The documented way in is `laravel new LAPIS --vue`. On this machine it fails: the installer (v5.28.1, via `C:\Users\Pedro Alves\.config\herd\bin\laravel.bat`) exits 1 and emits only

```json
{"success":false,"name":"LAPIS","directory":"D:\\HERD/LAPIS","log":"...\\larXXXX.tmp","tail":""}
```

with a zero-byte log. It fails identically in a clean directory, so it is not a path or permissions problem — Composer 2.10.1 works, PHP 8.4.23 works, the target directory is writable.

Going around it, `composer create-project laravel/vue-starter-kit LAPIS` succeeds but installs **Laravel 12**: the starter kit's newest tag is `v1.0.2`, while `laravel/framework` is at `v13.20.0`. The kit's tags are stale; its `main` branch is not.

## Decision

```powershell
composer create-project laravel/vue-starter-kit LAPIS dev-main
```

## Consequences

**What this gives us**, all matching §5.2: Laravel 13.20.0, PHP 8.4.23, Inertia 3.0, Vue 3.5, TypeScript 5.2, Tailwind 4.1, Vite 8. Plus Fortify (registration, login, e-mail verification, password reset, 2FA, **passkeys**), Wayfinder, Larastan and 52 tests, none of which we had to write.

**Deviations from the spec, accepted:**

- **PHPUnit 12, not Pest.** §5.2 prefers Pest "quando não exista motivo contrário". The motivo contrário: the kit ships PHPUnit with a working `composer ci:check`, Plaanly is PHPUnit, and converting buys nothing.
- **PHP 8.4**, not 8.3. The spec says "8.3 ou superior".

**Costs.** `dev-main` is a moving branch, not a tag — `composer.lock` is what pins it, so the lock file is load-bearing (§5.3.5). Re-scaffolding at a later date will not reproduce this tree. Nothing about that affects the running app; it only matters if the scaffold is ever regenerated from scratch.

**Revisit when** the starter kit tags a Laravel 13 release, or the installer is fixed. Neither is worth waiting for — the resulting tree is the same.

## Also recorded

`herd link lapis` hangs and is unnecessary: `D:\HERD` is already a **parked** Herd path, so `d:\HERD\LAPIS` is served at `lapis.test` automatically. `herd secure lapis` also hangs — it needs UAC elevation, which a non-interactive shell cannot give it. HTTPS therefore answers with an untrusted certificate, and `APP_URL` is `http://lapis.test`, consistent with the other sites on this machine. Run `herd secure lapis` from an elevated terminal to switch to trusted TLS.
