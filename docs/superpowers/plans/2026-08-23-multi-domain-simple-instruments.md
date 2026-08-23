# Multi-domain Simple Instruments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expand Criação simples to canonical multi-domain, multi-item assessments and make the Excel grid reflect them faithfully.

**Architecture:** Reuse the existing implicit group, `InstrumentItem`, `ItemDomainAllocation`, request/builder guards, and workbook defined-name contract. The Vue form owns compact domain/question state only as a projection of its canonical item array.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12, Vue 3, TypeScript, Inertia, Tailwind 4, PhpSpreadsheet.

## Global Constraints

- No migrations, parallel assessment tables, formula changes, production actions, version bump, commit, or push.
- Preserve row 1 headers, row 2 students, and `LAPIS_ITEM_<column>` defined names.
- Preserve advanced creation capabilities and scored-item deletion guards.

---

### Task 1: Generalize server validation

**Files:**
- Modify: `app/Http/Requests/InstrumentRequest.php`
- Test: `tests/Feature/Assessment/QuickInstrumentCreationTest.php`

- [ ] Replace the one-item quick restrictions with canonical implicit-group, prepared-status, non-bonus validation.
- [ ] Add tests for 1×3, 5×5, variable counts/cotações, 70/30, invalid allocation totals, no scores/completion, edit compatibility and authorization/tenancy.
- [ ] Run the focused feature test.

### Task 2: Build compact simple-mode UI

**Files:**
- Modify: `resources/js/pages/instruments/InstrumentForm.vue`
- Modify: `resources/js/pages/instruments/InstrumentDomainAllocations.vue`

- [ ] Project selected domains and counts onto the canonical item array with global Q ordering.
- [ ] Implement visible even distribution and live item/domain totals.
- [ ] Add the collapsed percentage allocation editor while preserving advanced point mode.
- [ ] Generalize safe mode switching without resetting state.
- [ ] Run ESLint and vue-tsc.

### Task 3: Improve correction workbook

**Files:**
- Modify: `app/Services/Import/Correction/WriteLapisGrid.php`
- Test: `tests/Feature/Import/LapisGridTest.php`

- [ ] Load item domains and render domain-aware headings/styles without moving contract rows.
- [ ] Mark multi-domain items once and retain every ULID defined name.
- [ ] Test students, items, domains, cotações, grouping and identity.
- [ ] Run the focused workbook tests and programmatically inspect the generated workbook.

### Task 4: Verify compatibility and quality

**Files:**
- Modify only if a verification exposes a regression.

- [ ] Run relevant assessment/import suites.
- [ ] Run browser smoke if existing browser infrastructure is usable.
- [ ] Run all requested PHP, frontend, build, Composer CI and audit gates, recording exact results.
- [ ] Review `git diff` and confirm only expected files changed plus the ignored local Claude settings file.
