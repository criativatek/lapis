# ADR-0001 — MySQL 9.7 instead of PostgreSQL

- **Status:** Accepted
- **Date:** 2026-07-17
- **Deciders:** Pedro Alves (product owner), com análise técnica assistida

## Context

`Prompt_base_LAPIS_Claude.md` §5.2 specifies PostgreSQL, and §31 lists "substituir PostgreSQL por outro motor" as a decision that must not be taken without approval. This ADR records that approval and the reasoning, as §5.3 and §6.3 require.

The local environment already runs a DBngin MySQL 9.7.1 service named `lapis` on port 3308. When this was written the service existed but was **empty** — there was no `lapis` database inside it and no SQL dump anywhere on disk. The cost of choosing either engine was therefore zero.

## Decision

Use **MySQL 9.7.1**. Development runs against the DBngin service on `127.0.0.1:3308`, schema `lapis`, `utf8mb4_unicode_ci`.

## Rationale

What was actually compared, for this application:

| Concern | Verdict |
|---|---|
| Exact grade arithmetic | `DECIMAL` is exact in both. **Tie** — and this is the requirement §24.4 really cares about. |
| `CHECK` constraints | MySQL enforces them since 8.0.16. The domain model leans on them heavily (`empty ≠ zero`, override reason). **Tie** |
| Indexable JSON | Postgres `JSONB` + GIN is better. But §21.7 restricts JSON to "configurações verdadeiramente flexíveis e versionadas" — three columns in the whole design. **Small edge to Postgres, not decisive** |
| Row Level Security | Postgres only. **The one real advantage** |

RLS was the only argument with weight, and it does not survive contact with how this application is built. Using it means a non-superuser role plus `SET LOCAL app.org_id` on every request, job and console command. The isolation Lapispro actually relies on is the container-resolved tenant (ADR-0002), Policies, and the isolation tests — all engine-independent, and all already proven by `tests/Feature/Tenancy/`.

Against that, every other system this team runs — Plaanly, track2lab, impacto — is MySQL. Choosing Postgres would make Lapispro the only project with different backups, tooling and operational habits, in exchange for a feature that would not be switched on.

The spec chose PostgreSQL as a sound default, not because Lapispro needs something MySQL lacks.

## Consequences

**Accepted costs.** MySQL has no partial unique indexes, so "at most one active version per profile" is expressed as a `STORED` generated column plus a `UNIQUE` index (see `docs/domain-model.md`). Enums are `CHECK`-constrained strings rather than native types. Null-safe comparison in `CHECK` constraints must use `<=>`; a plain `=` against NULL yields NULL, the constraint passes, and the guard silently does nothing.

**Required.** CI provisions a MySQL service, not Postgres. `.env.example` documents port 3308. Local tests run on SQLite in-memory for speed, so CI running MySQL is what actually covers engine-specific behaviour — generated columns and `CHECK` constraints are exactly the things SQLite will not catch.

**Revisit if** RLS becomes a genuine requirement (for example, a regulator requiring database-level isolation of student data), or if a Postgres-only capability turns out to be load-bearing. Migrating is far cheaper before the assessment tables carry real grades than after.

## Alternatives rejected

- **PostgreSQL as specified.** Rejected: the one advantage (RLS) would not be used, and it isolates this project from the team's operational reality.
- **SQLite.** Not considered for production; used only for the local test suite.
