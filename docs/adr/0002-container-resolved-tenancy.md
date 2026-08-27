# ADR-0002 — Tenancy resolved in the container, not the session

- **Status:** Accepted
- **Date:** 2026-07-17

## Context

§7.1 requires shared-database, shared-schema multi-organization isolation with an `organization_id` on every tenant-owned entity, and explicitly warns: *"Não uses global scopes de forma cega se puderem esconder erros."* §31 forbids adopting a multi-tenancy package that reshapes the architecture without approval.

Lapispro holds grades belonging to minors. A cross-tenant leak here is a personal-data breach, not a bug report.

The obvious starting point was Plaanly, the team's existing Laravel SaaS. Its tenancy is `app/Traits/HasCompanyScope.php:12`:

```php
static::addGlobalScope('company', function (Builder $query) {
    if ($companyId = session('company_id')) {
        $query->where(..., $companyId);
    }
});
```

Two properties of this were measured in that codebase and are worth stating plainly:

1. **The scope silently disables itself when there is no session.** Outside HTTP — queued jobs, console commands, seeders — the `if` fails and the query returns *every company's rows*. The application then has to work around its own tenancy: there are **201 `withoutGlobalScopes()` calls** across `app/`, and a single digest command needs seven of them.
2. **It never protects validation.** Plaanly's own test suite documents this at `tests/Feature/AuditTenantScopingTest.php:11-16`: `exists:` runs on the query builder and never sees an Eloquent global scope, so `exists:students,id` accepts another tenant's id. Closing that hole is a permanent manual obligation on every `exists:` rule ever written.

## Decision

Resolve the tenant into a **container singleton** (`App\Support\Tenancy\CurrentOrganization`), never from the session, and make the scope **throw** rather than no-op when no tenant is resolved.

Four pieces:

- `CurrentOrganization` — singleton holding the resolved `Organization`. `id()` throws `TenantNotResolvedException` when unset. `runFor($organization, $callback)` is how jobs, commands, seeders and tests enter a tenant.
- `ResolveOrganization` (global `web` middleware) — best-effort. The session holds only a *candidate* id; membership is re-checked against the database on every request, so a tampered cookie cannot move a user into another organization. It never aborts, because logout, profile and e-mail verification are legitimately tenant-less.
- `RequireOrganization` (`organization` alias) — declared on routes that need a tenant; turns "no tenant" into a clear 403 instead of a 500.
- `BelongsToCurrentOrganization` — a validation rule that runs the check through the model, so the scope applies. It replaces every bare `exists:` on tenant-owned data.

## Rationale

Reading the tenant from the container rather than the session is what removes the need for escape hatches: HTTP, queue and console all resolve it the same way, so a job declares its tenant with `runFor()` instead of switching tenancy off.

Throwing instead of returning everything is the whole point. A silent fallback answers "which organization?" with "all of them" — the worst possible default for student data. A thrown exception is a 500 and a bug report; a silent fallback is a breach nobody notices. **Fail loud, fail closed.**

Genuine cross-organization reads (institutional aggregates, support tooling) still use Laravel's native `withoutGlobalScope('organization')`. The difference from Plaanly is that this stays rare and deliberate rather than being the price of making jobs run at all.

## Consequences

- Every job and command must pass `organization_id` explicitly and wrap work in `runFor()`. This is intentional friction.
- The `#[Fillable]` allow-list plus `Model::preventSilentlyDiscardingAttributes()` stop a request from setting `organization_id` itself.
- Seeders and tests need a tenant. `UserFactory` therefore creates the personal organization by default, mirroring registration, which creates both in one transaction.
- **No multi-tenancy package** (`stancl/tenancy`, `spatie/laravel-multitenancy`) — §31 forbids it without approval, and the whole implementation is four small files.

## Verification

`tests/Feature/Tenancy/` covers this and is the acceptance gate for Fase 0:

- Scenario A7 — a record id from organization B is invisible to organization A.
- The scope **throws** rather than leaking when no tenant is resolved — a direct regression test against Plaanly's failure mode.
- A session `organization_id` the user is not a member of is ignored.
- `BelongsToCurrentOrganization` rejects another organization's id, with the assertion message naming what a bare `exists:` would have done.
