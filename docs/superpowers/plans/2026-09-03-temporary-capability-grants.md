# Temporary Capability Grants Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add temporary, auditable capability access through redeemable codes and direct platform assignment without changing subscriptions or plan versions.

**Architecture:** Six new tables persist presets, vouchers, grants, their frozen module sets, and redemptions. Focused actions own issuance/redeeming/direct assignment/revocation/disablement, while `Entitlements::resolve()` alone merges active grants after explicit overrides.

**Tech Stack:** Laravel 13.20, PHP 8.4, MySQL 9.7/SQLite tests, Vue 3, TypeScript, Inertia 3, Tailwind 4, PHPUnit 12.

## Global Constraints

- Never modify `plans`, `plan_versions`, `module_plan_version`, or entitlement catalogue/composition arrays.
- Validate module keys against the persisted catalogue.
- Preserve tenant isolation and use the container-resolved tenant.
- Use English in code/database and pt-PT in UI.
- Every commit bumps `config/app.php` and updates `CHANGELOG.md`.

---

### Task 1: Persistence, resolver, and actions

**Files:** Create the capability migration, enum, models, code helper, exceptions, five actions, and resolver tests; modify `Entitlements.php`.

- [ ] Add failing resolver and lifecycle tests using `calendar_import` and `lessons`.
- [ ] Create six tables with foreign keys, uniqueness, indexes, and MySQL checks.
- [ ] Add immutable models and relationships with ULID route keys.
- [ ] Implement issuance, atomic redemption, direct assignment, revocation, disablement, and audit events.
- [ ] Merge valid grants after overrides while retaining explicit locks.
- [ ] Run targeted tests, Pint, and Larastan; bump version/changelog and commit.

### Task 2: HTTP boundaries

**Files:** Create admin/settings Form Requests and controllers; modify `routes/admin.php`, `routes/settings.php`, and account/commercial payload controllers.

- [ ] Add platform-admin routes for presets, vouchers, direct grants, and revocation.
- [ ] Add owner-authorized, throttled organization redemption route.
- [ ] Return catalogue, presets, vouchers, usage, grants, and status payloads.
- [ ] Add authorization/validation/audit feature tests.
- [ ] Run relevant gates; bump version/changelog and commit.

### Task 3: Inertia UI

**Files:** Create or extend admin capability pages, `CommercialAccount.vue`, and the organization plan settings page.

- [ ] Add catalogue-driven checklists and preset-prefill behavior.
- [ ] Add voucher generation/list/disable controls.
- [ ] Add direct assignment and active/past grant list/revoke controls.
- [ ] Add code redemption with clear validation errors and loading states.
- [ ] Run ESLint, vue-tsc, SSR build, and relevant tests; bump version/changelog and commit.

### Task 4: Documentation and complete verification

**Files:** Modify `docs/backoffice.md`, `docs/status.md`; create the next ADR.

- [ ] Document the operational flows and the separation from plans/commercial vouchers.
- [ ] Record the architecture decision and privacy/tenancy implications.
- [ ] Run targeted capability tests, complete PHPUnit, Pint, Larastan, ESLint, vue-tsc, Vitest when applicable, and SSR build.
- [ ] Confirm protected plan files are unchanged against the branch merge-base.
- [ ] Bump version/changelog, commit, and report hashes and gate evidence.
